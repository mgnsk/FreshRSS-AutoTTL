<?php

class AutoTTLStats extends Minz_ModelPdo
{
    private function calcAdjustedTTL(int $avgTTL, int $feedTTL, int $dateMax): int
    {
        if ($feedTTL == FreshRSS_Feed::TTL_DEFAULT) {
            $feedTTL = FreshRSS_Context::$user_conf->ttl_default;
        }

        $maxTTL = (int) FreshRSS_Context::$user_conf->auto_ttl_max_ttl;
        $timeSinceLastEntry = time() - $dateMax;

        if ($feedTTL > $maxTTL) {
            return $feedTTL;
        }

        if ($avgTTL === 0 || $avgTTL > $maxTTL || $timeSinceLastEntry > 2 * $maxTTL) {
            return $maxTTL;
        } elseif ($avgTTL < $feedTTL) {
            return $feedTTL;
        }

        return $avgTTL;
    }

    public function getAdjustedTTL(FreshRSS_Feed $feed): int
    {
        $sql = <<<SQL
SELECT
	CASE WHEN stats.count > 0 THEN ((stats.date_max - stats.date_min) / stats.count) ELSE 0 END AS avgTTL,
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
            (int) $res['avgTTL'],
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
	CASE WHEN stats.count > 0 THEN ((stats.date_max - stats.date_min) / stats.count) ELSE 0 END AS avgTTL,
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
ORDER BY avgTTL ASC
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetchAll(PDO::FETCH_NAMED);
        $now = new \DateTime();
        $now->setTimezone(new DateTimeZone(date_default_timezone_get()));

        foreach ($res as $i => $feedStat) {
            $adjustedTTL = $this->calcAdjustedTTL(
                (int) $feedStat['avgTTL'],
                (int) $feedStat['ttl'],
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

    return join(" ", $results);
}
