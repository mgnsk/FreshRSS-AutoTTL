<?php

class StatItem
{
    public int $id;

    public string $name;

    public int $lastError;

    public FeedAttempt $attempt;

    public int $baseTTL;

    public int $ttl;

    public int $avgTTL;

    /*
     * See RetryAfter::forFeed(); 0 when FreshRSS has no active pause for this feed.
     */
    public int $retryAfter;

    public function __construct(array $feed, int $baseTTL)
    {
        $this->id = (int) $feed['id'];
        $this->name = html_entity_decode($feed['name']);
        $this->lastError = (int) ($feed['error'] ?? 0);
        $this->attempt = FeedAttempt::fromTimestamps((int) $feed['lastUpdate'], $this->lastError);

        $this->baseTTL = $baseTTL;
        $this->ttl = (int) $feed['ttl'];
        $this->avgTTL = (int) $feed['avgTTL'];
        $this->retryAfter = self::calcRetryAfter($feed);
    }

    private static function calcRetryAfter(array $feed): int
    {
        if (!isset($feed['url']) || $feed['url'] === '') {
            return 0;
        }

        $freshRSSFeed = new FreshRSS_Feed((string) $feed['url'], false);
        $freshRSSFeed->_attributes((string) ($feed['attributes'] ?? ''));

        return RetryAfter::forFeed($freshRSSFeed);
    }
}
