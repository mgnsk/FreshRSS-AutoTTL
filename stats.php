<?php

class AutoTTLStats extends Minz_ModelPdo
{
    private function calcAdjustedTTL(int $avgTTL, int $dateMax): int
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

    // fetchAllStats returns stats for AutoTTL enabled feeds.
    public function fetchAllStats(): array
    {
        $limit = FreshRSS_Context::$user_conf->auto_ttl_stats_count;

        $sql = <<<SQL
SELECT
	feed.id,
	feed.name,
	feed.`lastUpdate`,
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
WHERE feed.ttl = 0
ORDER BY `avgTTL` ASC
LIMIT {$limit}
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);
        $now = new \DateTime();
        $now->setTimezone(new DateTimeZone(date_default_timezone_get()));

        foreach ($res as $i => $feedStat) {
            $adjustedTTL = $this->calcAdjustedTTL(
                (int) $feedStat['avgTTL'],
                (int) $feedStat['date_max'],
            );
            $res[$i]['adjustedTTL'] = $adjustedTTL;

            $nextUpdate = DateTime::createFromFormat('U', (int) $feedStat['lastUpdate'] + $adjustedTTL);
            $nextUpdate->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $res[$i]['nextUpdateAfter'] = human_interval($nextUpdate->diff($now));
        }

        return $res;
    }
}

function human_interval(\DateInterval $interval): string
{
    $results = [];

    if ($interval->y > 0) {
        $results[] = "{$interval->y} years";
    }

    if ($interval->m > 0) {
        $results[] = "{$interval->m} months";
    }

    if ($interval->d > 0) {
        $results[] = "{$interval->d} days";
    }

    if ($interval->h > 0) {
        $results[] = "{$interval->h} hours";
    }

    if ($interval->i > 0) {
        $results[] = "{$interval->i} minutes";
    }

    return implode(' ', $results);
}
