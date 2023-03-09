<?php

class AutoTTLStats extends Minz_ModelPdo
{
    private function calcAdjustedTTL(int $avgTTL, int $minTTL, int $dateMax): int
    {
        if ($minTTL == FreshRSS_Feed::TTL_DEFAULT) {
            $minTTL = FreshRSS_Context::$user_conf->ttl_default;
        }

        $timeSinceLastEntry = time() - $dateMax;
        $maxTTL = (int) FreshRSS_Context::$user_conf->auto_ttl_max_ttl;

        if ($avgTTL === 0 || $avgTTL > $maxTTL || $timeSinceLastEntry > 2 * $maxTTL) {
            return $maxTTL;
        } elseif ($avgTTL < $minTTL) {
            return $minTTL;
        }

        return $avgTTL;
    }

    public function getAdjustedTTL(FreshRSS_Feed $feed): int
    {
        $sql = <<<SQL
SELECT
	CASE WHEN stats.count > 0 THEN ((stats.date_max - stats.date_min) / stats.count) ELSE 0 END AS avg_ttl,
	stats.date_max
FROM (
	SELECT
		COUNT(1) AS count,
		MIN(date) AS date_min,
		MAX(date) AS date_max
	FROM `entry`
	WHERE id_feed = {$feed->id()}
) stats
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetch(PDO::FETCH_NAMED);

        return $this->calcAdjustedTTL(
            (int) $res['avg_ttl'],
            $feed->ttl(),
            (int) $res['date_max']
        );
    }

    public function fetchAllStats(): array
    {
        $sql = <<<SQL
SELECT
	feed.name,
	feed.ttl,
	feed.lastUpdate,
	CASE WHEN stats.count > 0 THEN ((stats.date_max - stats.date_min) / stats.count) ELSE 0 END AS avg_ttl,
	stats.date_max
FROM (
	SELECT
		id_feed,
		COUNT(1) AS count,
		MIN(date) AS date_min,
		MAX(date) AS date_max
	FROM `entry`
	GROUP BY id_feed
) AS stats
LEFT JOIN `feed` ON feed.id = stats.id_feed
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);

        foreach ($res as $i => $feedStat) {
            $adjustedTTL = $this->calcAdjustedTTL(
                (int) $feedStat['avg_ttl'],
                (int) $feedStat['ttl'],
                (int) $feedStat['date_max'],
            );
            $res[$i]['adjusted_ttl'] = $adjustedTTL;
            $nextUpdate = (int) $feedStat['lastUpdate'] + $adjustedTTL;
            $res[$i]['next_update_in'] = $nextUpdate - time();
        }

        // Sort here to avoid unix timestamps in SQL for compatibility.
        usort($res, function ($a, $b) {
            return $a['next_update_in'] - $b['next_update_in'];
        });

        return $res;
    }
}
