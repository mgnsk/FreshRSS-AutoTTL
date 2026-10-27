<?php

class AutoTTLStats extends Minz_ModelPdo
{
    private readonly AutoTTLConfig $config;

    public function __construct(AutoTTLConfig $config)
    {
        parent::__construct();

        $this->config = $config;
    }

    public function calcAdjustedTTL(int $avgTTL, int $lastAttempt = 0, int $lastUpdate = 0): int
    {
        // A feed that has kept failing for longer than maxTTL since its last
        // successful update is likely dead: check it no more than once per
        // maxTTL instead of waiting for its entries to age out of the stats
        // window. lastAttempt only exceeds lastUpdate while the feed is erroring.
        if ($lastUpdate > 0 && $lastAttempt - $lastUpdate > $this->config->maxTTL) {
            $avgTTL = 0;
        }

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
     * Public so formatTimeUntilNextUpdate() can snap a feed's own configured
     * TTL (feeds not using AutoTTL) the same way.
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

    /*
     * The average is anchored at the last successful update, not the last
     * attempt: failed attempts bring in no entries, so counting the time they
     * add would stretch the average for as long as a feed keeps failing. The
     * resulting TTL is still counted from (and snapped against) the last attempt.
     */
    public function getAdjustedTTL(int $feedID, int $lastUpdate, int $lastAttempt): int
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
                return $this->calcAdjustedTTL((int) $res['avgTTL'], $lastAttempt, $lastUpdate);
            }
        }

        $info = $stm === false ? $this->pdo->errorInfo() : $stm->errorInfo();
        Minz_Log::error('AutoTTL SQL error ' . __METHOD__ . ' ' . json_encode($info));

        return $this->calcAdjustedTTL(0, $lastAttempt, $lastUpdate);
    }

    public function getFeedStats(bool $usesAutoTTL): array
    {
        $where = '';
        if ($usesAutoTTL) {
            $where = 'feed.ttl = 0';
        } else {
            $where = 'feed.ttl != 0';
        }

        // Anchored at the last successful update, same as getAdjustedTTL(), so the
        // displayed adjusted TTL matches the one feedBeforeActualizeHook() applies.
        $lastUpdate = 'feed.`lastUpdate`';

        $sql = <<<SQL
SELECT
    feed.id,
    feed.name,
    feed.url,
    feed.attributes,
    feed.`lastUpdate`,
    feed.error,
    feed.ttl,
    COALESCE(({$lastUpdate} - MIN(stats.date)) / COUNT(1), 0) AS `avgTTL`
FROM `_feed` AS feed
LEFT JOIN (
    SELECT id_feed, date
    FROM `_entry`
    WHERE date > {$this->getStatsCutoff()}
) AS stats ON feed.id = stats.id_feed AND stats.date <= {$lastUpdate}
WHERE {$where}
GROUP BY feed.id
ORDER BY COALESCE(({$lastUpdate} - MIN(stats.date)) / COUNT(1), 0) = 0, `avgTTL` ASC
LIMIT {$this->config->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        if ($stm !== false) {
            $list = [];
            foreach ($stm->fetchAll(PDO::FETCH_NAMED) as $feed) {
                $attempt = FeedAttempt::fromTimestamps((int) $feed['lastUpdate'], (int) ($feed['error'] ?? 0));
                $baseTTL = $this->calcAdjustedTTL((int) $feed['avgTTL'], $attempt->lastAttempt, $attempt->lastUpdate);

                $list[] = new StatItem($feed, $baseTTL);
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

        // An adjusted TTL already lands on a predicted sweep, but a feed's own
        // configured TTL doesn't; snap so the displayed countdown always reaches
        // zero on an actual sweep.
        $effectiveTTL = $this->snapToNextSweep($feed->attempt->lastAttempt, $ttl);
        $timeUntil = $feed->attempt->lastAttempt + $effectiveTTL - $now;

        // FreshRSS's own Retry-After pause (after a 429/503) blocks the fetch
        // until it expires, even when the TTL is already due.
        if ($feed->retryAfter > $now && $feed->retryAfter - $now > $timeUntil) {
            return 'rate limited, retry in ' . self::humanIntervalFromSeconds($feed->retryAfter - $now);
        }

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
