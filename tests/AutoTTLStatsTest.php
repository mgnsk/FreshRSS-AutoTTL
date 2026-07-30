<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require '/var/www/FreshRSS/cli/_cli.php';

FreshRSS_Context::initUser('admin');

class MockTime implements TimeSource
{
    private $ts;

    public function __construct(int $ts)
    {
        $this->ts = $ts;
    }

    public function time(): int
    {
        return $this->ts;
    }
}

final class AutoTTLStatsTest extends TestCase
{
    private $feedId;

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function test_default_ttl_gt_max_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 3599;
        $statsCount = 100;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, $statsCount);
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_avg_ttl_zero(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $statsCount = 100;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, $statsCount);
        $adjustedTTL = $stats->calcAdjustedTTL(0);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_gt_max_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $statsCount = 100;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, $statsCount);
        $adjustedTTL = $stats->calcAdjustedTTL($maxTTL + 1);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_lt_default_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $statsCount = 100;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, $statsCount);
        $adjustedTTL = $stats->calcAdjustedTTL($defaultTTL - 1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_get_avg_ttl_three_per_day(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $statsCount = 100;

        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, $statsCount);
            $stats->setTimeSource(new MockTime(strtotime("2000-01-02T00:00:00Z")));
            $adjustedTTL = $stats->getAdjustedTTL($feed->id());

            // (16:00 - 00:00) / 3 = 57600 seconds / 3 = 19200 seconds
            $this->assertSame(19200, $adjustedTTL);
        } finally {
            FreshRSS_feed_Controller::deleteFeed($feed->id());
        }
    }
}
