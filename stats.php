<?php

class StatItem
{
    // FreshRSS_Feed::lastError() legacy-returns 1 (not a real timestamp) for feeds
    // whose error state predates the _feed.error column becoming a BIGINT timestamp.
    public const LEGACY_ERROR_SENTINEL = 1;

    // Minimum size of the random skip range, in sweeps, even on a feed's very
    // first error. Without this floor, a fresh error's range would start at 1
    // sweep with no randomness at all - exactly the case that matters most for
    // a shared rate-limit event hitting many feeds in the same sweep.
    public const MIN_SKIP_SWEEPS = 4;

    public int $id;

    public string $name;

    public int $lastUpdate;

    public int $lastError;

    public int $lastAttempt;

    public bool $isErroring;

    public int $baseTTL;

    public int $backoffTTL;

    public int $ttl;

    public int $avgTTL;

    public function __construct(array $feed, int $baseTTL, int $maxTTL, int $cronIntervalEstimate = 0, int $groupRank = 0, int $groupSize = 1, string $host = '')
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastUpdate = (int) $feed['lastUpdate'];
        $this->lastError = (int) ($feed['error'] ?? 0);
        $this->lastAttempt = self::calcLastAttempt($this->lastUpdate, $this->lastError);
        $this->isErroring = self::calcIsErroring($this->lastUpdate, $this->lastError);
        $this->baseTTL = $baseTTL;
        $this->backoffTTL = self::calcBackoffTTL($baseTTL, $this->lastUpdate, $this->lastAttempt, $this->isErroring, $maxTTL, $cronIntervalEstimate, $groupRank, $groupSize, $host);
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
    }

    /*
     * Random number of sweeps (>= 1) an erroring feed skips beyond baseTTL's own
     * sweep. Only ever called for a feed that is part of a shared-host error
     * group (groupSize > 1, e.g. many YouTube channel feeds erroring together on
     * a rate limit) - see calcBackoffTTL(), which gates backoff on group
     * membership before reaching here.
     *
     * The random range grows with how long the feed has been failing (errorAge in
     * whole sweeps), clamped to maxSweeps, so a group that keeps failing gets both
     * a wider possible spread and a longer expected wait over time, without needing
     * to persist an attempt counter. MIN_SKIP_SWEEPS floors the range even on a
     * fresh error, and maxSweeps caps it even when MIN_SKIP_SWEEPS would otherwise
     * exceed it (a coarse cron relative to maxTTL) - either way, skip never exceeds
     * maxSweeps, preserving the same eventual-retry guarantee maxTTL provides today.
     *
     * The range is bumped to fit the whole group and each member's groupRank
     * claims a distinct slot within it, instead of leaving dispersal to hash luck
     * - see AutoTTLStats::getErrorHostGroups(). hostOffset gives each host group a
     * distinct per-host offset so different host groups don't all default to slot
     * 0 for their rank-0 member.
     */
    public static function calcSkipSweeps(int $lastUpdate, int $lastAttempt, int $cronInterval, int $maxSweeps, int $groupRank = 0, int $groupSize = 1, string $host = ''): int
    {
        $errorAge = $lastAttempt - $lastUpdate;
        $range = min($maxSweeps, max(self::MIN_SKIP_SWEEPS, intdiv($errorAge, $cronInterval)));
        $range = max(1, $range);

        // Bump the range so a large simultaneous group actually gets enough
        // distinct slots to spread into, still capped at maxSweeps.
        $range = min($maxSweeps, max($range, $groupSize));

        // Distinct per-host offset so different host groups don't all default
        // to slot 0 for their rank-0 member.
        $hostOffset = (int) (abs(crc32($host)) % $range);
        $slot = ($groupRank + $hostOffset) % $range;

        return 1 + $slot;
    }

    /*
     * Whether the feed's most recent fetch attempt ended in an error.
     * Guards against the legacy sentinel value of lastError(), which is not comparable to lastUpdate().
     */
    public static function calcIsErroring(int $lastUpdate, int $lastError): bool
    {
        return $lastError === self::LEGACY_ERROR_SENTINEL || $lastError > $lastUpdate;
    }

    /*
     * Timestamp of the feed's most recent fetch attempt (success or error).
     * Falls back to lastUpdate() when lastError() is the legacy sentinel, since
     * its real timestamp is unknown.
     */
    public static function calcLastAttempt(int $lastUpdate, int $lastError): int
    {
        if ($lastError <= self::LEGACY_ERROR_SENTINEL) {
            return $lastUpdate;
        }

        return max($lastUpdate, $lastError);
    }

    /*
     * TTL to apply to a feed that keeps erroring.
     *
     * When cronInterval > 0 (cron cadence learned), the feed randomly skips a
     * growing number of cron sweeps via calcSkipSweeps() - see there for why the
     * skip is randomized rather than a fixed doubling schedule. backoffTTL always
     * lands on a real predicted sweep by construction, so snapToNextSweep() has
     * nothing left to correct once the cron interval is known.
     *
     * When cronInterval == 0 (not learned yet), falls back to a bootstrap
     * seconds-based formula: since each retry only happens after waiting
     * backoffTTL, errorAge roughly doubles each pass once it exceeds baseTTL,
     * producing exponential backoff bounded by maxTTL without needing to persist
     * an attempt counter. No per-feed randomization is available yet at this
     * stage (see calcSkipSweeps) - this is a short bootstrap window before the
     * cron cadence is learned, not a steady-state throttling strategy.
     *
     * Never returns less than baseTTL, even if baseTTL itself exceeds maxTTL
     * (calcAdjustedTTL's defaultTTL > maxTTL escape hatch) - an errored feed
     * must never be checked more eagerly than a healthy one.
     *
     * groupSize <= 1 means no other AutoTTL-managed feed is currently erroring
     * on the same host (see AutoTTLStats::getErrorHostGroups()) - back-off only
     * exists to spread out a shared-host pile-up, so a lone erroring feed always
     * returns baseTTL unchanged, same as a non-erroring feed.
     */
    public static function calcBackoffTTL(int $baseTTL, int $lastUpdate, int $lastAttempt, bool $isErroring, int $maxTTL, int $cronInterval = 0, int $groupRank = 0, int $groupSize = 1, string $host = ''): int
    {
        if (!$isErroring || $groupSize <= 1) {
            return $baseTTL;
        }

        if ($cronInterval > 0) {
            // Budget sweeps from the headroom remaining after baseTTL, not from
            // maxTTL alone - otherwise a large baseTTL leaves no room and the
            // result below can overshoot maxTTL.
            $headroom = $maxTTL - $baseTTL;
            $maxSweeps = $headroom > 0 ? max(1, intdiv($headroom, $cronInterval) + 1) : 1;
            $skipSweeps = self::calcSkipSweeps($lastUpdate, $lastAttempt, $cronInterval, $maxSweeps, $groupRank, $groupSize, $host);

            return max($baseTTL, min($maxTTL, $baseTTL + ($skipSweeps - 1) * $cronInterval));
        }

        $errorAge = $lastAttempt - $lastUpdate;

        return max($baseTTL, min($maxTTL, max($baseTTL, $errorAge)));
    }
}

class CronIntervalEstimator
{
    // Real cron/systemd cadences are never sub-minute in practice, so any
    // gap shorter than this is assumed to be two feeds in the same
    // actualize sweep, not two separate sweeps.
    public const MIN_SWEEP_GAP = 60;

    /**
     * @return array{estimate: int, lastHookTs: int}
     */
    public static function updateEstimate(int $now, int $lastHookTs, int $estimate): array
    {
        if ($lastHookTs === 0) {
            // First observation ever for this user - nothing to compare against yet.
            return ['estimate' => $estimate, 'lastHookTs' => $now];
        }

        $gap = $now - $lastHookTs;
        if ($gap < self::MIN_SWEEP_GAP) {
            // Still within the same actualize sweep; not a new sample.
            return ['estimate' => $estimate, 'lastHookTs' => $lastHookTs];
        }

        // New sweep detected. Ratchet up immediately on a bigger gap (safe
        // to trust right away - a gap that big really did happen); ease
        // down gradually on a smaller one, so a single fast fluke doesn't
        // instantly undercut backoff for erroring feeds.
        $newEstimate = $gap > $estimate
            ? $gap
            : (int) (0.7 * $estimate + 0.3 * $gap);

        return ['estimate' => $newEstimate, 'lastHookTs' => $now];
    }
}

class AutoTTLStats extends Minz_ModelPdo
{
    /**
     * @var int
     */
    private $defaultTTL;

    /**
     * @var int
     */
    private $maxTTL;

    /**
     * @var int
     */
    private $statsCount;

    /**
     * @var int
     */
    private $minTTL;

    /**
     * @var int
     */
    private $cronLastHookTs;

    /**
     * @var int
     */
    private $cronIntervalEstimate;

    /**
     * @var ?array<int, array{rank: int, size: int, host: string}>
     */
    private $errorHostGroups = null;

    public function __construct(int $defaultTTL, int $maxTTL, int $statsCount, int $minTTL = 0, int $cronLastHookTs = 0, int $cronIntervalEstimate = 0)
    {
        parent::__construct();

        $this->defaultTTL = $defaultTTL;
        $this->maxTTL = $maxTTL;
        $this->statsCount = $statsCount;
        $this->minTTL = $minTTL;
        $this->cronLastHookTs = $cronLastHookTs;
        $this->cronIntervalEstimate = $cronIntervalEstimate;
    }

    public function calcAdjustedTTL(int $avgTTL, int $lastAttempt = 0): int
    {
        if ($this->defaultTTL > $this->maxTTL) {
            $result = $this->defaultTTL;
        } elseif ($avgTTL <= 0 || $avgTTL > $this->maxTTL) {
            $result = $this->maxTTL;
        } elseif ($avgTTL < $this->defaultTTL) {
            $result = $this->defaultTTL;
        } else {
            $result = $avgTTL;
        }

        // FreshRSS itself never fetches a feed more often than its own hidden
        // HTTP cache floor (limits.cache_duration), regardless of TTL, so AutoTTL's
        // computed TTL must never claim to be shorter than that.
        $result = max($result, $this->minTTL);

        return $this->snapToNextSweep($lastAttempt, $result);
    }

    /*
     * Rounds a TTL forward so lastAttempt + ttl lands on the actual cron
     * sweep at or after where it would otherwise fall, instead of sometime
     * before it - which is what leaves a feed sitting on "pending" for the
     * rest of the interval. A no-op (returns $ttl unchanged) when the cron
     * cadence hasn't been learned yet - see sampleCronInterval().
     *
     * "At or after" includes the sweep the anchor itself points at: an
     * already-due feed must resolve to that one, never to the following one -
     * see predictedSweepAtOrAfter().
     *
     * Public so callers combining calcAdjustedTTL()'s result with further TTL
     * adjustments - namely the bootstrap error backoff formula - can
     * re-snap the final total the same way, since it can push lastAttempt + ttl
     * past the sweep boundary calcAdjustedTTL() already snapped it to.
     */
    public function snapToNextSweep(int $lastAttempt, int $ttl): int
    {
        // lastAttempt <= 0 means "never attempted" (see calcLastAttempt()) - there
        // is no real anchor to measure a predicted sweep against, so snapping would
        // turn nextSweep's absolute timestamp into a nonsensical TTL.
        if ($lastAttempt <= 0) {
            return $ttl;
        }

        $nextSweep = $this->predictedSweepAtOrAfter($lastAttempt + $ttl);

        return $nextSweep !== null ? $nextSweep - $lastAttempt : $ttl;
    }

    /*
     * Smallest cronLastHookTs + n*cronIntervalEstimate (n >= 0) that is >= targetTime.
     * Null when the cron cadence hasn't been learned yet - see sampleCronInterval().
     *
     * n = 0 (the anchor sweep itself) is essential, not an edge case:
     * sampleCronInterval() moves cronLastHookTs to now at the start of every
     * sweep, so a feed that is already due has its target behind the anchor.
     * Forcing n >= 1 there would answer "the sweep after this one" every single
     * time, pushing the due time a full interval into the future on each sweep -
     * a treadmill the feed can never reach, leaving it never refreshed while its
     * displayed countdown restarts at one cron interval after every sweep.
     */
    private function predictedSweepAtOrAfter(int $targetTime): ?int
    {
        if ($this->cronIntervalEstimate <= 0 || $this->cronLastHookTs <= 0) {
            return null;
        }

        $diff = $targetTime - $this->cronLastHookTs;
        $sweepsAhead = $diff <= 0
            ? 0
            : intdiv($diff + $this->cronIntervalEstimate - 1, $this->cronIntervalEstimate);

        return $this->cronLastHookTs + $sweepsAhead * $this->cronIntervalEstimate;
    }

    public function getAdjustedTTL(int $feedID, int $lastUpdate): int
    {
        $sql = <<<SQL
SELECT
    COALESCE(({$lastUpdate} - MIN(stats.date)) / COUNT(1), 0) AS `avgTTL`
FROM `_entry` AS stats
WHERE id_feed = {$feedID} AND date > {$this->getStatsCutoff()} AND date <= {$lastUpdate}
SQL;

        $stm = $this->pdo->query($sql);
        if ($stm !== false) {
            $res = $stm->fetch(PDO::FETCH_NAMED);
            if ($res !== false) {
                return $this->calcAdjustedTTL((int) $res['avgTTL'], $lastUpdate);
            }
        }

        $info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
        Minz_Log::error('AutoTTL SQL error ' . __METHOD__ . ' ' . json_encode($info));

        return $this->calcAdjustedTTL(0, $lastUpdate);
    }

    /*
     * Groups currently-erroring, AutoTTL-managed feeds by their URL's exact
     * hostname, so calcSkipSweeps() can hand out distinct sweep slots to feeds
     * sharing a rate-limited host instead of relying on independent hash luck.
     * Computed lazily and memoized for the lifetime of this instance - i.e. once
     * per sweep, since AutoTTLExtension::getStats() reuses a single instance
     * across all feeds in a request - and only queried at all once something
     * actually calls getGroupInfoForFeed().
     *
     * Feeds still on the legacy error sentinel (no real error timestamp) are
     * excluded; they fall back to the ungrouped per-feed hash, same as feeds
     * whose URL host can't be determined (each gets its own singleton group,
     * keyed by feed id so it can never collide with a real hostname).
     *
     * @return array<int, array{rank: int, size: int, host: string}>
     */
    private function getErrorHostGroups(): array
    {
        if ($this->errorHostGroups !== null) {
            return $this->errorHostGroups;
        }
        $this->errorHostGroups = [];

        $sql = <<<SQL
SELECT feed.id, feed.url
FROM `_feed` AS feed
WHERE feed.ttl = 0 AND feed.error > 1 AND feed.error > feed.`lastUpdate`
SQL;

        $stm = $this->pdo->query($sql);
        if ($stm === false) {
            Minz_Log::error('AutoTTL SQL error ' . __METHOD__ . ' ' . json_encode($this->pdo->errorInfo()));

            return $this->errorHostGroups;
        }

        $byGroupKey = [];
        foreach ($stm->fetchAll(PDO::FETCH_NAMED) as $row) {
            $feedId = (int) $row['id'];
            $host = parse_url((string) $row['url'], PHP_URL_HOST);
            $groupKey = (is_string($host) && $host !== '') ? strtolower($host) : ('#feed:' . $feedId);
            $byGroupKey[$groupKey][] = $feedId;
        }

        foreach ($byGroupKey as $groupKey => $feedIds) {
            sort($feedIds); // stable ascending rank
            $size = count($feedIds);
            $host = str_starts_with($groupKey, '#feed:') ? '' : $groupKey;
            foreach ($feedIds as $rank => $feedId) {
                $this->errorHostGroups[$feedId] = ['rank' => $rank, 'size' => $size, 'host' => $host];
            }
        }

        return $this->errorHostGroups;
    }

    /**
     * @return array{rank: int, size: int, host: string}
     */
    public function getGroupInfoForFeed(int $feedId): array
    {
        return $this->getErrorHostGroups()[$feedId] ?? ['rank' => 0, 'size' => 1, 'host' => ''];
    }

    public function getFeedStats(bool $usesAutoTTL): array
    {
        $where = '';
        if ($usesAutoTTL) {
            $where = 'feed.ttl = 0';
        } else {
            $where = 'feed.ttl != 0';
        }

        // Mirrors StatItem::calcLastAttempt(): the legacy error sentinel (1) is
        // not a real timestamp, so it's excluded from the "last attempt" anchor used below.
        // This must match the anchor the throttle engine uses (feedBeforeActualizeHook),
        // otherwise the displayed adjusted TTL diverges from the one actually applied.
        $lastAttempt = "(CASE WHEN feed.error > 1 AND feed.error > feed.`lastUpdate` THEN feed.error ELSE feed.`lastUpdate` END)";

        $sql = <<<SQL
SELECT
    feed.id,
    feed.name,
    feed.`lastUpdate`,
    feed.error,
    feed.ttl,
    COALESCE(({$lastAttempt} - MIN(stats.date)) / COUNT(1), 0) AS `avgTTL`
FROM `_feed` AS feed
LEFT JOIN (
    SELECT id_feed, date
    FROM `_entry`
    WHERE date > {$this->getStatsCutoff()}
) AS stats ON feed.id = stats.id_feed AND stats.date <= {$lastAttempt}
WHERE {$where}
GROUP BY feed.id
ORDER BY COALESCE(({$lastAttempt} - MIN(stats.date)) / COUNT(1), 0) = 0, `avgTTL` ASC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        if ($stm !== false) {
            $list = [];
            foreach ($stm->fetchAll(PDO::FETCH_NAMED) as $feed) {
                $lastAttemptTs = StatItem::calcLastAttempt((int) $feed['lastUpdate'], (int) ($feed['error'] ?? 0));
                $isErroring = StatItem::calcIsErroring((int) $feed['lastUpdate'], (int) ($feed['error'] ?? 0));
                $baseTTL = $this->calcAdjustedTTL((int) $feed['avgTTL'], $lastAttemptTs);

                $groupInfo = ['rank' => 0, 'size' => 1, 'host' => ''];
                if ($isErroring) {
                    $groupInfo = $this->getGroupInfoForFeed((int) $feed['id']);
                }

                $list[] = new StatItem($feed, $baseTTL, $this->maxTTL, $this->cronIntervalEstimate, $groupInfo['rank'], $groupInfo['size'], $groupInfo['host']);
            }

            return $list;
        }

        Minz_Log::error('AutoTTL SQL error ' . __METHOD__ . ' ' . json_encode($this->pdo->errorInfo()));

        return [];
    }

    public function formatLastAttempt(StatItem $feed, int $now): string
    {
        if ($feed->isErroring) {
            return $feed->lastError > StatItem::LEGACY_ERROR_SENTINEL
                ? 'error ' . self::humanIntervalFromSeconds($now - $feed->lastError) . ' ago'
                : 'error (time unknown)';
        }

        return $feed->lastUpdate > 0
            ? self::humanIntervalFromSeconds($now - $feed->lastUpdate) . ' ago'
            : 'never';
    }

    public function formatTimeUntilNextUpdate(StatItem $feed, int $ttl, int $now): string
    {
        if ($feed->lastAttempt === 0) {
            return 'never attempted';
        }

        // $ttl already lands on a predicted sweep when the cron interval is known
        // (calcAdjustedTTL()/calcBackoffTTL() both snap by construction), but the
        // bootstrap backoff formula (cron interval not yet learned) doesn't;
        // re-snap so the displayed countdown always reaches zero on an actual
        // sweep, matching what feedBeforeActualizeHook() does with the same value.
        $effectiveTTL = $this->snapToNextSweep($feed->lastAttempt, $ttl);
        $timeUntil = $feed->lastAttempt + $effectiveTTL - $now;

        $suffix = $feed->isErroring ? ' (in error)' : '';

        return ($timeUntil > 0 ? self::humanIntervalFromSeconds($timeUntil) : 'pending') . $suffix;
    }

    private function getStatsCutoff(): int
    {
        // Get entry stats from last 30 days only
        // so we don't depend on old entries and purge policy so much.
        return time() - 30 * 24 * 60 * 60;
    }

    public static function humanIntervalFromSeconds(int $seconds): string
    {
        $from = new \DateTime('@0');
        $to = new \DateTime("@$seconds");
        $interval = $from->diff($to);

        $results = [];

        if ($interval->y === 1) {
            $results[] = "{$interval->y} year";
        } elseif ($interval->y > 1) {
            $results[] = "{$interval->y} years";
        }

        if ($interval->m === 1) {
            $results[] = "{$interval->m} month";
        } elseif ($interval->m > 1) {
            $results[] = "{$interval->m} months";
        }

        if ($interval->d === 1) {
            $results[] = "{$interval->d} day";
        } elseif ($interval->d > 1) {
            $results[] = "{$interval->d} days";
        }

        if ($interval->h === 1) {
            $results[] = "{$interval->h} hour";
        } elseif ($interval->h > 1) {
            $results[] = "{$interval->h} hours";
        }

        if ($interval->i === 1) {
            $results[] = "{$interval->i} minute";
        } elseif ($interval->i > 1) {
            $results[] = "{$interval->i} minutes";
        } elseif ($interval->i === 0 && $interval->s === 1) {
            $results[] = "{$interval->s} second";
        } elseif ($interval->i === 0 && $interval->s > 1) {
            $results[] = "{$interval->s} seconds";
        }

        return implode(' ', $results);
    }
}
