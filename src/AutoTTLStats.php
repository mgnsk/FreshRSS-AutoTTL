<?php

class AutoTTLStats extends Minz_ModelPdo
{
    private readonly AutoTTLConfig $config;

    /**
     * @var ?array<int, ErrorGroupInfo>
     */
    private $errorHostGroups = null;

    public function __construct(AutoTTLConfig $config)
    {
        parent::__construct();

        $this->config = $config;
    }

    public function calcAdjustedTTL(int $avgTTL, int $lastAttempt = 0): int
    {
        if ($this->config->defaultTTL > $this->config->maxTTL) {
            $result = $this->config->defaultTTL;
        } elseif ($avgTTL <= 0 || $avgTTL > $this->config->maxTTL) {
            $result = $this->config->maxTTL;
        } elseif ($avgTTL < $this->config->defaultTTL) {
            $result = $this->config->defaultTTL;
        } else {
            $result = $avgTTL;
        }

        // FreshRSS itself never fetches a feed more often than its own hidden
        // HTTP cache floor (limits.cache_duration), regardless of TTL, so AutoTTL's
        // computed TTL must never claim to be shorter than that.
        $result = max($result, $this->config->minTTL);

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
        if ($this->config->cronIntervalEstimate <= 0 || $this->config->cronLastHookTs <= 0) {
            return null;
        }

        $diff = $targetTime - $this->config->cronLastHookTs;
        $sweepsAhead = $diff <= 0
            ? 0
            : intdiv($diff + $this->config->cronIntervalEstimate - 1, $this->config->cronIntervalEstimate);

        return $this->config->cronLastHookTs + $sweepsAhead * $this->config->cronIntervalEstimate;
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
     * @return array<int, ErrorGroupInfo>
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
                $this->errorHostGroups[$feedId] = new ErrorGroupInfo($rank, $size, $host);
            }
        }

        return $this->errorHostGroups;
    }

    public function getGroupInfoForFeed(int $feedId): ErrorGroupInfo
    {
        return $this->getErrorHostGroups()[$feedId] ?? new ErrorGroupInfo();
    }

    public function getFeedStats(bool $usesAutoTTL): array
    {
        $where = '';
        if ($usesAutoTTL) {
            $where = 'feed.ttl = 0';
        } else {
            $where = 'feed.ttl != 0';
        }

        // Mirrors FeedAttempt::calcLastAttempt(): the legacy error sentinel (1) is
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
LIMIT {$this->config->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        if ($stm !== false) {
            $list = [];
            foreach ($stm->fetchAll(PDO::FETCH_NAMED) as $feed) {
                $attempt = FeedAttempt::fromTimestamps((int) $feed['lastUpdate'], (int) ($feed['error'] ?? 0));
                $baseTTL = $this->calcAdjustedTTL((int) $feed['avgTTL'], $attempt->lastAttempt);

                $groupInfo = new ErrorGroupInfo();
                if ($attempt->isErroring) {
                    $groupInfo = $this->getGroupInfoForFeed((int) $feed['id']);
                }

                $list[] = new StatItem($feed, $baseTTL, $this->config->maxTTL, $this->config->cronIntervalEstimate, $groupInfo);
            }

            return $list;
        }

        Minz_Log::error('AutoTTL SQL error ' . __METHOD__ . ' ' . json_encode($this->pdo->errorInfo()));

        return [];
    }

    public function formatLastAttempt(StatItem $feed, int $now): string
    {
        if ($feed->attempt->isErroring) {
            return $feed->lastError > FeedAttempt::LEGACY_ERROR_SENTINEL
                ? 'error ' . self::humanIntervalFromSeconds($now - $feed->lastError) . ' ago'
                : 'error (time unknown)';
        }

        return $feed->attempt->lastUpdate > 0
            ? self::humanIntervalFromSeconds($now - $feed->attempt->lastUpdate) . ' ago'
            : 'never';
    }

    public function formatTimeUntilNextUpdate(StatItem $feed, int $ttl, int $now): string
    {
        if ($feed->attempt->lastAttempt === 0) {
            return 'never attempted';
        }

        // $ttl already lands on a predicted sweep when the cron interval is known
        // (calcAdjustedTTL()/calcBackoffTTL() both snap by construction), but the
        // bootstrap backoff formula (cron interval not yet learned) doesn't;
        // re-snap so the displayed countdown always reaches zero on an actual
        // sweep, matching what feedBeforeActualizeHook() does with the same value.
        $effectiveTTL = $this->snapToNextSweep($feed->attempt->lastAttempt, $ttl);
        $timeUntil = $feed->attempt->lastAttempt + $effectiveTTL - $now;

        $suffix = $feed->attempt->isErroring ? ' (in error)' : '';

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
