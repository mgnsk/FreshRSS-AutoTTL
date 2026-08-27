<?php

class StatItem
{
    public int $id;

    public string $name;

    public int $lastError;

    public FeedAttempt $attempt;

    public int $baseTTL;

    public int $backoffTTL;

    public int $ttl;

    public int $avgTTL;

    public function __construct(array $feed, int $baseTTL, int $maxTTL, int $cronIntervalEstimate = 0, ?ErrorGroupInfo $group = null)
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastError = (int) ($feed['error'] ?? 0);
        $this->attempt = FeedAttempt::fromTimestamps((int) $feed['lastUpdate'], $this->lastError);

        $this->baseTTL = $baseTTL;
        $this->backoffTTL = BackoffCalculator::calcBackoffTTL($baseTTL, $this->attempt, $maxTTL, $cronIntervalEstimate, $group);
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
    }
}
