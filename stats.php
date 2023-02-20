<?php

class StatResult
{
    /**
     * @var int
     */
    private $dateMin = 0;

    /**
     * @var int
     */
    private $dateMax = 0;

    /**
     * @var int
     */
    private $count = 0;

    public function __construct(int $dateMin, int $dateMax, int $count)
    {
        $this->dateMin = $dateMin;
        $this->dateMax = $dateMax;
        $this->count = $count;
    }

    /**
     * Calculates the average seconds between articles.
     * Returns 0 when not enough articles or all articles have the same timestamp.
     */
    public function getAvgTTL(): int
    {
        if ($this->count > 0) {
            $interval_in_seconds = $this->dateMax - $this->dateMin;
            return (int) ($interval_in_seconds / $this->count);
        }

        return 0;
    }

    /**
     * Returns the timestamp of the last article.
     */
    public function getMaxDate(): int
    {
        return $this->dateMax;
    }
}

class AutoTTLStats extends Minz_ModelPdo
{
    /**
     * Calculates the average seconds between articles.
     * Returns 0 when not enough articles or all articles have the same timestamp.
     */
    public function fetchStats(int $feed): StatResult
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

        return new StatResult(
            (int) $res['date_min'],
            (int) $res['date_max'],
            (int) $res['count']
        );
    }
}
