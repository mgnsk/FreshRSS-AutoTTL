<?php

class StatItem
{
    public int $id;

    public string $name;

    public int $lastUpdate;

    public int $ttl;

    public int $avgTTL;

    public int $dateMax;

    private int $maxTTL;

    public function __construct(array $feed, int $maxTTL)
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastUpdate = (int) $feed['lastUpdate'];
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
        $this->dateMax = (int) $feed['date_max'];
        $this->maxTTL = $maxTTL;
    }

    public function isActive(int $now): bool
    {
        $timeSinceLastEntry = $now - $this->dateMax;

        if ($timeSinceLastEntry > 2 * $this->maxTTL) {
            return false;
        }

        return true;
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

    public function __construct(int $defaultTTL, int $maxTTL, int $statsCount)
    {
        parent::__construct();

        $this->defaultTTL = $defaultTTL;
        $this->maxTTL = $maxTTL;
        $this->statsCount = $statsCount;
    }

    public function calcAdjustedTTL(int $avgTTL, int $dateMax): int
    {
        $timeSinceLastEntry = time() - $dateMax;

        if ($this->defaultTTL > $this->maxTTL) {
            return $this->defaultTTL;
        }

        if ($avgTTL === 0 || $avgTTL > $this->maxTTL || $timeSinceLastEntry > 2 * $this->maxTTL) {
            return $this->maxTTL;
        } elseif ($avgTTL < $this->defaultTTL) {
            return $this->defaultTTL;
        }

        return $avgTTL;
    }

    public function getAdjustedTTL(int $feedID): int
    {
        $sql = <<<SQL
SELECT
	CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
	MAX(stats.date) AS date_max
FROM `_entry` AS stats
WHERE id_feed = {$feedID}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetch(PDO::FETCH_NAMED);

        return $this->calcAdjustedTTL((int) $res['avgTTL'], (int) $res['date_max']);
    }

    public function getFeedStats(bool $autoTTL): array
    {
        $where = '';
        if ($autoTTL) {
            $where = 'feed.ttl = 0';
        } else {
            $where = 'feed.ttl != 0';
        }

        $sql = <<<SQL
SELECT
	feed.id,
	feed.name,
	feed.`lastUpdate`,
	feed.ttl,
	CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
	MAX(stats.date) AS date_max
FROM `_feed` AS feed
LEFT JOIN `_entry` AS stats ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY feed.id
ORDER BY `avgTTL` ASC
LIMIT {$this->statsCount}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        $list = [];
        foreach ($res as $feed) {
            $list[] = new StatItem($feed, $this->maxTTL);
        }

        return $list;
    }

    public function humanIntervalFromSeconds(int $seconds): string
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
