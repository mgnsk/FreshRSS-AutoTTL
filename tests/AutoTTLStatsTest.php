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

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_avg_ttl_zero(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(0);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_negative(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
        $adjustedTTL = $stats->calcAdjustedTTL(-100);

        // maxTTL returned, not defaultTTL: a negative avgTTL (e.g. from a feed
        // with future-dated entries) means "not enough data", same as zero.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_gt_max_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
        $adjustedTTL = $stats->calcAdjustedTTL($maxTTL + 1);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_lt_default_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
        $adjustedTTL = $stats->calcAdjustedTTL($defaultTTL - 1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_get_avg_ttl_three_per_day(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
            $stats->setTimeSource(new MockTime(strtotime("2000-01-02T00:00:00Z")));
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), strtotime("2000-01-01T16:00:00Z"));

            // (16:00 - 00:00) / 3 = 57600 seconds / 3 = 19200 seconds
            $this->assertSame(19200, $adjustedTTL);
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
        }
    }

    public function test_get_avg_two_close(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
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
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
        }
    }

    public function test_get_avg_ttl_future_dated_entries_ignored(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/future_dated.xml');

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
            $stats->setTimeSource(new MockTime(strtotime("2000-01-02T00:00:00Z")));
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), strtotime("2000-01-01T16:00:00Z"));

            // Entries dated in 2027/2028 are after lastAttempt, so they must be
            // excluded from the average instead of making it negative. avgTTL
            // resolves to 0 ("not enough data"), which must map to maxTTL, not
            // defaultTTL (regression test for linuxdaw.org/rss.xml issue).
            $this->assertSame($maxTTL, $adjustedTTL);
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
        }
    }

    public function test_get_feed_stats(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $autoTTLFeed = null;
        $erroredFeed = null;
        $customTTLFeed = null;
        $futureDatedFeed = null;
        try {
            $autoTTLFeed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');
            $erroredFeed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');
            $customTTLFeed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml?custom_ttl');
            $futureDatedFeed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/future_dated.xml');

            $feedDAO = FreshRSS_Factory::createFeedDao();
            $now = time();

            // getFeedStats() reads feed.lastUpdate straight from the DB (unlike
            // getAdjustedTTL(), which takes it as a parameter), so pin it to a
            // known value to make the avgTTL assertion below deterministic.
            $feedDAO->updateLastUpdate($autoTTLFeed->id(), strtotime('2000-01-01T16:00:00Z'));
            $feedDAO->updateLastUpdate($futureDatedFeed->id(), strtotime('2000-01-01T16:00:00Z'));

            // Push lastUpdate back first so the error timestamp set below is more
            // recent than it, which is what makes calcIsErroring() consider the feed erroring.
            $feedDAO->updateLastUpdate($erroredFeed->id(), $now - 86400);
            $feedDAO->updateLastError($erroredFeed->id(), $now - 600);
            $feedDAO->updateFeed($customTTLFeed->id(), ['ttl' => 1800]);

            $stats = new AutoTTLStats($defaultTTL, $maxTTL, 100);
            $stats->setTimeSource(new MockTime(strtotime('2000-01-02T00:00:00Z')));

            $autoTTLStats = $stats->getFeedStats(true);
            $autoTTLIds = array_map(fn (StatItem $item) => $item->id, $autoTTLStats);

            $this->assertContains($autoTTLFeed->id(), $autoTTLIds);
            $this->assertContains($erroredFeed->id(), $autoTTLIds);
            $this->assertContains($futureDatedFeed->id(), $autoTTLIds);
            $this->assertNotContains($customTTLFeed->id(), $autoTTLIds);

            foreach ($autoTTLStats as $item) {
                if ($item->id === $autoTTLFeed->id()) {
                    $this->assertFalse($item->isErroring);
                    // (16:00 - 00:00) / 3 = 19200 seconds
                    $this->assertSame(19200, $item->avgTTL);
                } elseif ($item->id === $erroredFeed->id()) {
                    $this->assertTrue($item->isErroring);
                } elseif ($item->id === $futureDatedFeed->id()) {
                    // Entries dated in 2027/2028 are after lastUpdate, so they must
                    // be excluded from the average instead of making it negative.
                    // avgTTL resolves to 0 ("not enough data"), which must map to
                    // maxTTL, not defaultTTL (regression test for linuxdaw.org/rss.xml issue).
                    $this->assertSame(0, $item->avgTTL);
                    $this->assertSame($maxTTL, $item->baseTTL);
                }
            }

            $customTTLStats = $stats->getFeedStats(false);
            $customTTLIds = array_map(fn (StatItem $item) => $item->id, $customTTLStats);

            $this->assertContains($customTTLFeed->id(), $customTTLIds);
            $this->assertNotContains($autoTTLFeed->id(), $customTTLIds);
            $this->assertNotContains($erroredFeed->id(), $customTTLIds);
        } finally {
            if ($autoTTLFeed !== null) {
                FreshRSS_feed_Controller::deleteFeed($autoTTLFeed->id());
            }
            if ($erroredFeed !== null) {
                FreshRSS_feed_Controller::deleteFeed($erroredFeed->id());
            }
            if ($customTTLFeed !== null) {
                FreshRSS_feed_Controller::deleteFeed($customTTLFeed->id());
            }
            if ($futureDatedFeed !== null) {
                FreshRSS_feed_Controller::deleteFeed($futureDatedFeed->id());
            }
        }
    }

    public function test_stat_item_last_attempt(): void
    {
        $baseTTL = 3600;
        $maxTTL = 86400;

        $item1 = new StatItem([
            'id' => 1,
            'name' => 'Test Feed',
            'lastUpdate' => 1000,
            'error' => 500,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL);

        $this->assertSame(1000, $item1->lastUpdate);
        $this->assertSame(500, $item1->lastError);
        $this->assertSame(1000, $item1->lastAttempt);
        $this->assertFalse($item1->isErroring);
        $this->assertSame($baseTTL, $item1->baseTTL);
        $this->assertSame($baseTTL, $item1->backoffTTL);
        $this->assertSame(0, $item1->errorJitter);

        // errorAge (1000s) below baseTTL (3600s): backoff stays at baseTTL.
        $item2 = new StatItem([
            'id' => 2,
            'name' => 'Errored Feed',
            'lastUpdate' => 1000,
            'error' => 2000,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL);

        $this->assertSame(1000, $item2->lastUpdate);
        $this->assertSame(2000, $item2->lastError);
        $this->assertSame(2000, $item2->lastAttempt);
        $this->assertTrue($item2->isErroring);
        $this->assertSame($baseTTL, $item2->baseTTL);
        $this->assertSame($baseTTL, $item2->backoffTTL);
        $this->assertGreaterThanOrEqual(0, $item2->errorJitter);
        $this->assertLessThan((int) ($baseTTL * StatItem::JITTER_FRACTION), $item2->errorJitter);

        // errorAge (7200s) above baseTTL: backoff grows to match errorAge.
        $item3 = new StatItem([
            'id' => 3,
            'name' => 'Long Errored Feed',
            'lastUpdate' => 1000,
            'error' => 8200,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL);

        $this->assertSame($baseTTL, $item3->baseTTL);
        $this->assertSame(7200, $item3->backoffTTL);
        $this->assertLessThan((int) (7200 * StatItem::JITTER_FRACTION), $item3->errorJitter);

        // errorAge far exceeding maxTTL: backoff clamps at maxTTL.
        $item4 = new StatItem([
            'id' => 4,
            'name' => 'Deeply Errored Feed',
            'lastUpdate' => 1000,
            'error' => 1000 + 200000,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL);

        $this->assertSame($maxTTL, $item4->backoffTTL);
    }

    public function test_calc_backoff_ttl_grows_and_clamps(): void
    {
        $baseTTL = 3600;
        $maxTTL = 86400;
        $lastUpdate = 0;

        // Not erroring: backoff is always just baseTTL.
        $this->assertSame($baseTTL, StatItem::calcBackoffTTL($baseTTL, $lastUpdate, 100000, false, $maxTTL));

        // errorAge below baseTTL: clamped up to baseTTL.
        $this->assertSame($baseTTL, StatItem::calcBackoffTTL($baseTTL, $lastUpdate, 1800, true, $maxTTL));

        // Simulate consecutive throttled retries: each retry only happens after
        // waiting the previous backoffTTL, so errorAge grows by that amount each
        // pass. Once past baseTTL, this produces roughly-doubling backoff.
        $errorAge = $baseTTL;
        $backoffs = [];
        for ($i = 0; $i < 5; $i++) {
            $backoff = StatItem::calcBackoffTTL($baseTTL, $lastUpdate, $errorAge, true, $maxTTL);
            $backoffs[] = $backoff;
            $errorAge += $backoff;
        }

        $this->assertSame([3600, 7200, 14400, 28800, 57600], $backoffs);

        // Clamped at maxTTL however large errorAge grows.
        $this->assertSame($maxTTL, StatItem::calcBackoffTTL($baseTTL, $lastUpdate, $maxTTL * 10, true, $maxTTL));
    }

    public function test_calc_backoff_ttl_never_below_base_ttl_when_base_exceeds_max(): void
    {
        // Mirrors calcAdjustedTTL's defaultTTL > maxTTL escape hatch (see
        // test_default_ttl_gt_max_ttl): baseTTL can legitimately exceed maxTTL.
        // An errored feed must still never be checked more eagerly than a
        // healthy one, so backoff must not collapse down to maxTTL here.
        $baseTTL = 7200;
        $maxTTL = 3600;
        $lastUpdate = 0;

        $this->assertSame($baseTTL, StatItem::calcBackoffTTL($baseTTL, $lastUpdate, 100, true, $maxTTL));
        $this->assertSame($baseTTL, StatItem::calcBackoffTTL($baseTTL, $lastUpdate, $baseTTL * 10, true, $maxTTL));
    }

    public function test_feed_before_actualize_throttles_recent_error(): void
    {
        $feed = null;
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
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
        }
    }

    public function test_error_jitter_staggers_errored_feeds(): void
    {
        $feed1 = null;
        $feed2 = null;
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

            $baseTTL = 3600;

            // 1. Non-errored feed should return 0 jitter
            $feed1Clean = $feedDAO->searchById($feed1->id());
            $backoffClean = $ext->getBackoffTTL($feed1Clean, $baseTTL);
            $this->assertSame(0, $ext->getErrorJitter($feed1Clean, $backoffClean));

            // 2. Errored feeds should return non-negative jitter within [0, 25% of backoffTTL)
            $errorTime = $now - 600;
            $feedDAO->updateLastError($feed1->id(), $errorTime);
            $feedDAO->updateLastError($feed2->id(), $errorTime);

            $feed1Error = $feedDAO->searchById($feed1->id());
            $feed2Error = $feedDAO->searchById($feed2->id());

            $backoff1 = $ext->getBackoffTTL($feed1Error, $baseTTL);
            $backoff2 = $ext->getBackoffTTL($feed2Error, $baseTTL);

            $jitter1 = $ext->getErrorJitter($feed1Error, $backoff1);
            $jitter2 = $ext->getErrorJitter($feed2Error, $backoff2);

            $this->assertGreaterThanOrEqual(0, $jitter1);
            $this->assertLessThan((int) ($backoff1 * StatItem::JITTER_FRACTION), $jitter1);

            $this->assertGreaterThanOrEqual(0, $jitter2);
            $this->assertLessThan((int) ($backoff2 * StatItem::JITTER_FRACTION), $jitter2);

            // Jitter is per-feed, so feeds erroring at the same instant should generally be
            // staggered. Any two specific feed IDs have a low chance of colliding on the
            // same jitter bucket, so assert the stagger property across many synthetic feed IDs
            // instead of just $feed1/$feed2, which would make the test flaky.
            $jitters = [];
            for ($syntheticFeedId = 1; $syntheticFeedId <= 20; $syntheticFeedId++) {
                $jitters[] = StatItem::calcErrorJitter($syntheticFeedId, $baseTTL, $errorTime, true);
            }
            $this->assertGreaterThan(1, count(array_unique($jitters)), 'Expected jitter to vary across feeds');

            // 3. Jitter calculation should be deterministic for the same feed and error timestamp
            $this->assertSame($jitter1, $ext->getErrorJitter($feed1Error, $backoff1));
            $this->assertSame($jitter2, $ext->getErrorJitter($feed2Error, $backoff2));
        } finally {
            if ($feed1 !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed1->id());
            }
            if ($feed2 !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed2->id());
            }
        }
    }
}
