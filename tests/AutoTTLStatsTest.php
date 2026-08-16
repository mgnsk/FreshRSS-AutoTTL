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

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100, 3600);
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_avg_ttl_zero(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100, 3600);
        $adjustedTTL = $stats->calcAdjustedTTL(0);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_gt_max_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100, 3600);
        $adjustedTTL = $stats->calcAdjustedTTL($maxTTL + 1);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_lt_default_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100, 3600);
        $adjustedTTL = $stats->calcAdjustedTTL($defaultTTL - 1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_get_avg_ttl_three_per_day(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100, 3600);
            $stats->setTimeSource(new MockTime(strtotime("2000-01-02T00:00:00Z")));
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), strtotime("2000-01-01T16:00:00Z"));

            // (16:00 - 00:00) / 3 = 57600 seconds / 3 = 19200 seconds
            $this->assertSame(19200, $adjustedTTL);
        } finally {
            FreshRSS_feed_Controller::deleteFeed($feed->id());
        }
    }

    public function test_get_avg_two_close(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100, 3600);
            $stats->setTimeSource(new MockTime(strtotime("2000-01-04T00:00:00Z")));

            // Two updates in a row when we checked implies frequent updates.
            // (00:02 - 00:00) / 2 = 2 seconds / 2 = 1 seconds < $default
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), strtotime("2000-01-01T00:00:02Z"));
            $this->assertSame($defaultTTL, $adjustedTTL);

            // Two updates in a row, but hours ago, implies moderate updates.
            // (16:00 - 00:00) / 2 = 57600 seconds / 2 = 28800 seconds
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), strtotime("2000-01-01T16:00:00Z"));
            $this->assertSame(28800, $adjustedTTL);

            // Two updates in a row, but days ago, implies slow updates.
            // 2 days > 1 day $maxTTL
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), strtotime("2000-01-03T00:00:00Z"));
            $this->assertSame($maxTTL, $adjustedTTL);


        } finally {
            FreshRSS_feed_Controller::deleteFeed($feed->id());
        }
    }

    public function test_stat_item_last_attempt(): void
    {
        $item1 = new StatItem([
            'id' => 1,
            'name' => 'Test Feed',
            'lastUpdate' => 1000,
            'error' => 500,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], 3600);

        $this->assertSame(1000, $item1->lastUpdate);
        $this->assertSame(500, $item1->lastError);
        $this->assertSame(1000, $item1->lastAttempt);
        $this->assertSame(0, $item1->errorJitter);

        $item2 = new StatItem([
            'id' => 2,
            'name' => 'Errored Feed',
            'lastUpdate' => 1000,
            'error' => 2000,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], 3600);

        $this->assertSame(1000, $item2->lastUpdate);
        $this->assertSame(2000, $item2->lastError);
        $this->assertSame(2000, $item2->lastAttempt);
        $this->assertGreaterThanOrEqual(0, $item2->errorJitter);
        $this->assertLessThan(60 * 60, $item2->errorJitter);
    }

    public function test_feed_before_actualize_throttles_recent_error(): void
    {
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->maxTTL = 3600;

            // Set lastUpdate to 1 day ago so lastError takes precedence in max(lastUpdate, lastError)
            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed->id(), $now - 86400);

            // Simulate an error 10 minutes ago
            $feedDAO->updateLastError($feed->id(), $now - 600);
            $feed = $feedDAO->searchById($feed->id());

            // Since last error was 10 minutes ago, and TTL is 3600 (1 hour),
            // feedBeforeActualizeHook should return null (throttle feed)
            $result = $ext->feedBeforeActualizeHook($feed);
            $this->assertNull($result);

            // If error was 2 hours ago (7200s > 3600s maxTTL), feedBeforeActualizeHook should return $feed
            $feedDAO->updateLastError($feed->id(), $now - 7200);
            $feed = $feedDAO->searchById($feed->id());
            $result = $ext->feedBeforeActualizeHook($feed);
            $this->assertNotNull($result);
            $this->assertSame($feed->id(), $result->id());
        } finally {
            FreshRSS_feed_Controller::deleteFeed($feed->id());
        }
    }

    public function test_error_jitter_staggers_errored_feeds(): void
    {
        try {
            $feed1 = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');
            $feed2 = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();

            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed1->id(), $now - 86400);
            $feedDAO->updateLastUpdate($feed2->id(), $now - 86400);

            // 1. Non-errored feed should return 0 jitter
            $feed1Clean = $feedDAO->searchById($feed1->id());
            $this->assertSame(0, $ext->getErrorJitter($feed1Clean));

            // 2. Errored feeds should return non-negative jitter within 0..3599 seconds (1 hour)
            $errorTime = $now - 600;
            $feedDAO->updateLastError($feed1->id(), $errorTime);
            $feedDAO->updateLastError($feed2->id(), $errorTime);

            $feed1Error = $feedDAO->searchById($feed1->id());
            $feed2Error = $feedDAO->searchById($feed2->id());

            $jitter1 = $ext->getErrorJitter($feed1Error);
            $jitter2 = $ext->getErrorJitter($feed2Error);

            $this->assertGreaterThanOrEqual(0, $jitter1);
            $this->assertLessThan(60 * 60, $jitter1);

            $this->assertGreaterThanOrEqual(0, $jitter2);
            $this->assertLessThan(60 * 60, $jitter2);

            // Jitter is per-feed, so feeds erroring at the same instant should generally be
            // staggered. Any two specific feed IDs have a 1-in-3600 chance of colliding on the
            // same jitter bucket, so assert the stagger property across many synthetic feed IDs
            // instead of just $feed1/$feed2, which would make the test flaky.
            $jitters = [];
            for ($syntheticFeedId = 1; $syntheticFeedId <= 20; $syntheticFeedId++) {
                $jitters[] = StatItem::calcErrorJitter($syntheticFeedId, $now - 86400, $errorTime, 3600);
            }
            $this->assertGreaterThan(1, count(array_unique($jitters)), 'Expected jitter to vary across feeds');

            // 3. Jitter calculation should be deterministic for the same feed and error timestamp
            $this->assertSame($jitter1, $ext->getErrorJitter($feed1Error));
            $this->assertSame($jitter2, $ext->getErrorJitter($feed2Error));
        } finally {
            FreshRSS_feed_Controller::deleteFeed($feed1->id());
            FreshRSS_feed_Controller::deleteFeed($feed2->id());
        }
    }
}
