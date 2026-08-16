<?php

class StatItem
{
    // FreshRSS_Feed::lastError() legacy-returns 1 (not a real timestamp) for feeds
    // whose error state predates the _feed.error column becoming a BIGINT timestamp.
    public const LEGACY_ERROR_SENTINEL = 1;

    // Jitter is a fraction of backoffTTL rather than an absolute value, so it scales
    // with the wait instead of dwarfing short TTLs or being negligible on long ones.
    public const JITTER_FRACTION = 0.25;

    public int $id;

    public string $name;

    public int $lastUpdate;

    public int $lastError;

    public int $lastAttempt;

    public bool $isErroring;

    public int $baseTTL;

    public int $backoffTTL;

    public int $errorJitter;

    public int $ttl;

    public int $avgTTL;

    public function __construct(array $feed, int $baseTTL, int $maxTTL)
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastUpdate = (int) $feed['lastUpdate'];
        $this->lastError = (int) ($feed['error'] ?? 0);
        $this->lastAttempt = self::calcLastAttempt($this->lastUpdate, $this->lastError);
        $this->isErroring = self::calcIsErroring($this->lastUpdate, $this->lastError);
        $this->baseTTL = $baseTTL;
        $this->backoffTTL = self::calcBackoffTTL($baseTTL, $this->lastUpdate, $this->lastAttempt, $this->isErroring, $maxTTL);
        $this->errorJitter = self::calcErrorJitter($this->id, $this->backoffTTL, $this->lastError, $this->isErroring);
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
    }

    public static function calcErrorJitter(int $feedId, int $backoffTTL, int $lastError, bool $isErroring): int
    {
        if (!$isErroring) {
            return 0;
        }

        $jitterMax = (int) ($backoffTTL * self::JITTER_FRACTION);
        if ($jitterMax <= 0) {
            return 0;
        }

        return (int) (abs(crc32($feedId . '_' . $lastError)) % $jitterMax);
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
     * TTL to apply to a feed that keeps erroring, growing with how long it's been
     * failing (lastAttempt - lastUpdate). Since each retry only happens after
     * waiting backoffTTL, errorAge roughly doubles each pass once it exceeds
     * baseTTL, producing exponential backoff bounded by maxTTL without needing
     * to persist an attempt counter.
     *
     * Never returns less than baseTTL, even if baseTTL itself exceeds maxTTL
     * (calcAdjustedTTL's defaultTTL > maxTTL escape hatch) - an errored feed
     * must never be checked more eagerly than a healthy one.
     */
    public static function calcBackoffTTL(int $baseTTL, int $lastUpdate, int $lastAttempt, bool $isErroring, int $maxTTL): int
    {
        if (!$isErroring) {
            return $baseTTL;
        }

        $errorAge = $lastAttempt - $lastUpdate;

        return max($baseTTL, min($maxTTL, max($baseTTL, $errorAge)));
    }
}

interface TimeSource
{
    public function time(): int;
}

class DefaultTime implements TimeSource
{
    public function time(): int
    {
        return time();
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
     * @var TimeSource
     */
    private $timeSource;

    public function __construct(int $defaultTTL, int $maxTTL, int $statsCount)
    {
        parent::__construct();

        $this->defaultTTL = $defaultTTL;
        $this->maxTTL = $maxTTL;
        $this->statsCount = $statsCount;
        $this->timeSource = new DefaultTime();
    }

    public function setTimeSource(TimeSource $timeSource): void
    {
        $this->timeSource = $timeSource;
    }

    public function calcAdjustedTTL(int $avgTTL): int
    {
        if ($this->defaultTTL > $this->maxTTL) {
            return $this->defaultTTL;
        }

        if ($avgTTL === 0 || $avgTTL > $this->maxTTL) {
            return $this->maxTTL;
        } elseif ($avgTTL < $this->defaultTTL) {
            return $this->defaultTTL;
        }

        return $avgTTL;
    }

    public function getAdjustedTTL(int $feedID, int $lastUpdate): int
    {
        $sql = <<<SQL
SELECT
    COALESCE(({$lastUpdate} - MIN(stats.date)) / COUNT(1), 0) AS `avgTTL`
FROM `_entry` AS stats
WHERE id_feed = {$feedID} AND date > {$this->getStatsCutoff()}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetch(PDO::FETCH_NAMED);

        return $this->calcAdjustedTTL((int) $res['avgTTL']);
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
) AS stats ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY feed.id
ORDER BY `avgTTL` = 0, `avgTTL` ASC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        $list = [];
        foreach ($res as $feed) {
            $baseTTL = $this->calcAdjustedTTL((int) $feed['avgTTL']);
            $list[] = new StatItem($feed, $baseTTL, $this->maxTTL);
        }

        return $list;
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

    public function formatTimeUntilNextUpdate(StatItem $feed, int $ttl, int $now, bool $includeJitter): string
    {
        if ($feed->lastAttempt === 0) {
            return 'never attempted';
        }

        $jitter = $includeJitter ? $feed->errorJitter : 0;
        $timeUntil = $feed->lastAttempt + $ttl + $jitter - $now;

        $suffix = '';
        if ($feed->isErroring) {
            $jitterText = $jitter > 0 ? ', +' . self::humanIntervalFromSeconds($jitter) . ' jitter' : '';
            $suffix = ' (in error' . $jitterText . ')';
        }

        return ($timeUntil > 0 ? self::humanIntervalFromSeconds($timeUntil) : 'pending') . $suffix;
    }

    private function getStatsCutoff(): int
    {
        // Get entry stats from last 30 days only
        // so we don't depend on old entries and purge policy so much.
        return $this->timeSource->time() - 30 * 24 * 60 * 60;
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
