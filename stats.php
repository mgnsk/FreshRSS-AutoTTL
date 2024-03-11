<?php

class AutoTTLStats extends Minz_ModelPdo
{
    public function calcAdjustedTTL(int $avgTTL, int $dateMax): int
    {
        $defaultTTL = FreshRSS_Context::$user_conf->ttl_default;
        $maxTTL = (int) FreshRSS_Context::$user_conf->auto_ttl_max_ttl;
        $timeSinceLastEntry = time() - $dateMax;

        if ($defaultTTL > $maxTTL) {
            return $defaultTTL;
        }

        if ($avgTTL === 0 || $avgTTL > $maxTTL || $timeSinceLastEntry > 2 * $maxTTL) {
            return $maxTTL;
        } elseif ($avgTTL < $defaultTTL) {
            return $defaultTTL;
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
        $limit = FreshRSS_Context::$user_conf->auto_ttl_stats_count;

        $where = "";
        if ($autoTTL) {
            $where = "feed.ttl = 0";
        } else {
            $where = "feed.ttl != 0";
        }

        $sql = <<<SQL
SELECT
	stats.id_feed,
	feed.name,
	feed.`lastUpdate`,
	feed.ttl,
	CASE WHEN COUNT(1) > 0 THEN ((MAX(stats.date) - MIN(stats.date)) / COUNT(1)) ELSE 0 END AS `avgTTL`,
	MAX(stats.date) AS date_max
FROM `_entry` AS stats
JOIN `_feed` AS feed ON feed.id = stats.id_feed
WHERE {$where}
GROUP BY stats.id_feed
ORDER BY `avgTTL` ASC
LIMIT {$limit}
SQL;

        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        return $res;
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
        }

        return implode(' ', $results);
    }
}
