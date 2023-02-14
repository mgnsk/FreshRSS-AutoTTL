<?php

class AutoTTLStats extends Minz_ModelPdo
{
    /**
     * Calculates the average seconds between articles.
     * Returns 0 when not enough articles or all articles have the same timestamp.
     */
    public function calcAvgTTL(int $feed): int
    {
        $sql = <<<SQL
SELECT COUNT(1) AS count
, MIN(date) AS date_min
, MAX(date) AS date_max
FROM `_entry` AS e
WHERE e.id_feed = {$feed}
SQL;
        $stm = $this->pdo->query($sql);
        $res = $stm->fetch(PDO::FETCH_NAMED);

        if ($res['count'] > 0) {
            $interval_in_seconds =
                (int) $res['date_max'] - (int) $res['date_min'];
            return (int) ($interval_in_seconds / (int) $res['count']);
        }

        return 0;
    }
}
