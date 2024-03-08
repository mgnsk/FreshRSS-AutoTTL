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
	CASE WHEN stats.count > 0 THEN ((stats.date_max - stats.date_min) / stats.count) ELSE 0 END AS `avgTTL`,
	stats.date_max
FROM (
	SELECT
		COUNT(1) AS count,
		MIN(date) AS date_min,
		MAX(date) AS date_max
	FROM `_entry`
	WHERE id_feed = {$feedID}
) stats
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetch(PDO::FETCH_NAMED);

        return $this->calcAdjustedTTL(
            (int) $res['avgTTL'],
            (int) $res['date_max']
        );
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
	feed.id,
	feed.name,
	feed.`lastUpdate`,
	feed.ttl,
	CASE WHEN stats.count > 0 THEN ((stats.date_max - stats.date_min) / stats.count) ELSE 0 END AS `avgTTL`,
	stats.date_max
FROM (
	SELECT
		id_feed,
		COUNT(1) AS count,
		MIN(date) AS date_min,
		MAX(date) AS date_max
	FROM `_entry`
	GROUP BY id_feed
) AS stats
LEFT JOIN `_feed` as feed ON feed.id = stats.id_feed
WHERE {$where}
ORDER BY `avgTTL` ASC
LIMIT {$limit}
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        return $res;
    }

    public function humanInterval(\DateInterval $interval): string
    {
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

    public function humanIntervalFromSeconds(int $seconds): string
    {
        $from = new \DateTime('@0');
        $to = new \DateTime("@$seconds");
        $interval = $from->diff($to);

        return $this->humanInterval($interval);
    }
}
