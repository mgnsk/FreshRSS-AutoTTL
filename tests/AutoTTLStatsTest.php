<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require '/var/www/FreshRSS/cli/_cli.php';

FreshRSS_Context::initUser('admin');

final class AutoTTLStatsTest extends TestCase
{
    /* private $feedDao; */
    private $feedId;

    protected function setUp(): void
    {
        /* $this->feedDao = FreshRSS_Factory::createFeedDao(); */

        /* $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/feed_single_item.xml'); */
        /* $this->feedId = $feed->id(); */
    }

    protected function tearDown(): void
    {
        /* FreshRSS_feed_Controller::deleteFeed($this->feedId); */
    }

    public function test_default_ttl_gt_max_ttl(): void
    {
        $stats = new AutoTTLStats(3600, 3599, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        // defaultTTL returned.
        $this->assertSame(3600, $adjustedTTL);
    }

    public function test_avg_ttl_zero(): void
    {
        $stats = new AutoTTLStats(3600, 86400, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(0);

        // maxTTL returned.
        $this->assertSame(86400, $adjustedTTL);
    }

    public function test_avg_ttl_gt_max_ttl(): void
    {
        $stats = new AutoTTLStats(3600, 86400, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(86401);

        // maxTTL returned.
        $this->assertSame(86400, $adjustedTTL);
    }

    public function test_avg_ttl_lt_default_ttl(): void
    {
        $stats = new AutoTTLStats(3600, 86400, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(3599);

        // defaultTTL returned.
        $this->assertSame(3600, $adjustedTTL);
    }

    public function test_get_avg_ttl_three_per_day(): void
    {
        // TODO: 30 day window, time() override
        $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

        $stats = new AutoTTLStats(3600, 86400, 100);
        $adjustedTTL = $stats->getAdjustedTTL($feed->id());

        var_dump($adjustedTTL);

        FreshRSS_feed_Controller::deleteFeed($feed->id());
    }
}
