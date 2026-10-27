<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require '/var/www/FreshRSS/cli/_cli.php';

FreshRSS_Context::initUser('admin');

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

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        // defaultTTL returned.
        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_min_ttl_floors_computed_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $minTTL = 7200;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, $minTTL));
        $adjustedTTL = $stats->calcAdjustedTTL($defaultTTL);

        // avgTTL resolves to defaultTTL (3600) via the normal path, but minTTL (7200) floors it.
        $this->assertSame($minTTL, $adjustedTTL);
    }

    public function test_min_ttl_no_effect_when_computed_ttl_already_higher(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $minTTL = 1800;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, $minTTL));
        $adjustedTTL = $stats->calcAdjustedTTL(43200);

        // avgTTL (43200) already exceeds minTTL, so minTTL has no effect.
        $this->assertSame(43200, $adjustedTTL);
    }

    public function test_min_ttl_default_preserves_existing_behavior(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        // No 4th arg: minTTL defaults to 0, i.e. no floor - matches pre-change behavior.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
        $adjustedTTL = $stats->calcAdjustedTTL(0);

        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_min_ttl_floors_default_ttl_gt_max_ttl_escape_hatch(): void
    {
        // Mirrors test_default_ttl_gt_max_ttl, but minTTL exceeds even defaultTTL,
        // confirming the escape hatch is floored too.
        $defaultTTL = 3600;
        $maxTTL = 3599;
        $minTTL = 7200;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, $minTTL));
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        $this->assertSame($minTTL, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_no_cron_snapping_when_not_learned(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $now = time();

        // cronLastHookTs/cronIntervalEstimate default to 0 (not learned yet):
        // calcAdjustedTTL must ignore the lastAttempt anchor entirely.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
        $adjustedTTL = $stats->calcAdjustedTTL(1, $now - 100);

        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_snaps_forward_to_next_predicted_sweep(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $now = time();

        // Last sweep 100s ago, cron every 900s: predicted sweeps are at
        // now-100, now+800, now+1700, ... The raw TTL (defaultTTL, since
        // avgTTL=1 is below it) would put the due time at now+600 - in the gap
        // between two sweeps - so the result must be pushed out to now+800.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, 0, $now - 100, 900));
        $lastAttempt = $now - 3000;
        $adjustedTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        $this->assertSame(3800, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_does_not_defer_an_already_due_feed_past_the_current_sweep(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $now = time();

        // Same 900s cron, but here the raw due time (now-400) is already behind
        // the anchor, so the anchor's own sweep is the first one at or after it:
        // the feed is due now and must snap to that sweep (now-100), never to
        // the following one at now+800. sampleCronInterval() moves the anchor to
        // now on every sweep, so deferring here would defer forever.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, 0, $now - 100, 900));
        $lastAttempt = $now - 4000;
        $adjustedTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        $this->assertSame(3900, $adjustedTTL);
        // feedBeforeActualizeHook()'s gate is `elapsed < ttl`, so this must not
        // come out throttled.
        $this->assertLessThanOrEqual($now - $lastAttempt, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_sweep_boundary_does_not_overshoot(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $now = time();

        // lastAttempt is chosen so the raw due time (lastAttempt + defaultTTL)
        // lands exactly on the second predicted sweep (cronLastHookTs + 2*900).
        // It must resolve to that same sweep, not overshoot to the third one.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, 0, $now - 1000, 900));
        $lastAttempt = ($now + 800) - $defaultTTL;
        $adjustedTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_snaps_across_many_missed_sweeps(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $now = time();

        // cronLastHookTs is far in the past (a stale/long-idle estimate) -
        // the ceiling-division math must still land on the correct sweep
        // without drifting, rather than looping through each missed one.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, 0, $now - 100000, 900));
        $lastAttempt = $now - 5000;
        $adjustedTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        $this->assertSame(4000, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_no_snapping_for_never_attempted_feed_even_with_learned_cron(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;
        $now = time();

        // lastAttempt defaults to 0 ("never attempted") - even with a learned
        // cron interval, snapping must not turn the predicted sweep's absolute
        // timestamp into a nonsensical TTL (see snapToNextSweep()).
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, 0, $now - 100, 900));
        $adjustedTTL = $stats->calcAdjustedTTL(1);

        $this->assertSame($defaultTTL, $adjustedTTL);
    }

    public function test_calc_adjusted_ttl_combines_min_ttl_floor_with_cron_snapping(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 3599; // forces the escape hatch, same as test_default_ttl_gt_max_ttl
        $minTTL = 4000;
        $now = time();

        // Raw TTL (defaultTTL via the escape hatch) is first floored to 4000s
        // by minTTL, then that due time is snapped forward to the next
        // predicted sweep (cronLastHookTs + 1800), landing at 4600s total.
        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100, $minTTL, $now - 1000, 1800));
        $lastAttempt = $now - 3800;
        $adjustedTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        $this->assertSame(4600, $adjustedTTL);
    }

    public function test_avg_ttl_zero(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
        $adjustedTTL = $stats->calcAdjustedTTL(0);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_negative(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
        $adjustedTTL = $stats->calcAdjustedTTL(-100);

        // maxTTL returned, not defaultTTL: a negative avgTTL (e.g. from a feed
        // with future-dated entries) means "not enough data", same as zero.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_gt_max_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
        $adjustedTTL = $stats->calcAdjustedTTL($maxTTL + 1);

        // maxTTL returned.
        $this->assertSame($maxTTL, $adjustedTTL);
    }

    public function test_avg_ttl_lt_default_ttl(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
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
            // Captured after the fetch so it's always >= wiremock's own "now" used
            // to render the feed's entry dates, keeping the date <= lastUpdate
            // bound below safe from PHP/wiremock clock skew.
            $now = time();

            $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now, $now);

            // (now - -16h) / 3 = 57600 seconds / 3 = 19200 seconds
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
            // Captured after the fetch so it's always >= wiremock's own "now"
            // used to render the feed's entry dates (2 days ago and 2 days ago
            // + 2 seconds); see test_get_avg_ttl_three_per_day for why.
            $now = time();

            $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));

            // Two updates in a row when we checked implies frequent updates.
            // 2 seconds / 2 entries = 1 second < $defaultTTL
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now - 172798, $now - 172798);
            $this->assertSame($defaultTTL, $adjustedTTL);

            // Two updates in a row, but hours ago, implies moderate updates.
            // (-115200 - -172800) / 2 = 57600 seconds / 2 = 28800 seconds
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now - 115200, $now - 115200);
            $this->assertSame(28800, $adjustedTTL);

            // Two updates in a row, but days ago, implies slow updates.
            // 2 days > 1 day $maxTTL
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now, $now);
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

            $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), time(), time());

            // future_dated.xml's entries are dated via wiremock's "now" templating
            // helper, offset +2/+3 years, so they're always after lastUpdate (~now)
            // and must be excluded from the average instead of making it negative.
            // avgTTL resolves to 0 ("not enough data"), which must map to maxTTL,
            // not defaultTTL (regression test for linuxdaw.org/rss.xml issue).
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
            $feedDAO->updateLastUpdate($autoTTLFeed->id(), $now);
            $feedDAO->updateLastUpdate($futureDatedFeed->id(), $now);

            // Push lastUpdate back first so the error timestamp set below is more
            // recent than it, which is what makes calcIsErroring() consider the feed erroring.
            $feedDAO->updateLastUpdate($erroredFeed->id(), $now - 86400);
            $feedDAO->updateLastError($erroredFeed->id(), $now - 600);
            $feedDAO->updateFeed($customTTLFeed->id(), ['ttl' => 1800]);

            $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));

            $autoTTLStats = $stats->getFeedStats(true);
            $autoTTLIds = array_map(fn (StatItem $item) => $item->id, $autoTTLStats);

            $this->assertContains($autoTTLFeed->id(), $autoTTLIds);
            $this->assertContains($erroredFeed->id(), $autoTTLIds);
            $this->assertContains($futureDatedFeed->id(), $autoTTLIds);
            $this->assertNotContains($customTTLFeed->id(), $autoTTLIds);

            foreach ($autoTTLStats as $item) {
                if ($item->id === $autoTTLFeed->id()) {
                    $this->assertFalse($item->attempt->isErroring);
                    // (now - -16h) / 3 = 19200 seconds
                    $this->assertSame(19200, $item->avgTTL);
                } elseif ($item->id === $erroredFeed->id()) {
                    $this->assertTrue($item->attempt->isErroring);
                    // Anchored at the last successful update, not the more recent
                    // error: (-86400 - -172800) / 2 = 43200 seconds.
                    $this->assertSame(43200, $item->avgTTL);
                } elseif ($item->id === $futureDatedFeed->id()) {
                    // future_dated.xml's entries are dated via wiremock's "now"
                    // templating helper, offset +2/+3 years, so they're always
                    // after lastUpdate (~now) and must be excluded from the
                    // average instead of making it negative. avgTTL resolves to 0
                    // ("not enough data"), which must map to maxTTL, not defaultTTL
                    // (regression test for linuxdaw.org/rss.xml issue).
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

        $item1 = new StatItem([
            'id' => 1,
            'name' => 'Test Feed',
            'lastUpdate' => 1000,
            'error' => 500,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL);

        $this->assertSame(1000, $item1->attempt->lastUpdate);
        $this->assertSame(500, $item1->lastError);
        $this->assertSame(1000, $item1->attempt->lastAttempt);
        $this->assertFalse($item1->attempt->isErroring);
        $this->assertSame($baseTTL, $item1->baseTTL);
        $this->assertSame(0, $item1->retryAfter);

        $item2 = new StatItem([
            'id' => 2,
            'name' => 'Errored Feed',
            'lastUpdate' => 1000,
            'error' => 2000,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL);

        $this->assertSame(1000, $item2->attempt->lastUpdate);
        $this->assertSame(2000, $item2->lastError);
        $this->assertSame(2000, $item2->attempt->lastAttempt);
        $this->assertTrue($item2->attempt->isErroring);
        // An erroring feed keeps its normal adjusted TTL - no back-off.
        $this->assertSame($baseTTL, $item2->baseTTL);
    }

    public function test_cron_interval_estimator_first_observation_seeds_without_estimating(): void
    {
        $now = time();
        $result = CronIntervalEstimator::updateEstimate($now, 0, 0);

        // Nothing to compare the very first call against yet: just remember when it happened.
        $this->assertSame(0, $result->estimate);
        $this->assertSame($now, $result->lastHookTs);
    }

    public function test_cron_interval_estimator_ignores_intra_sweep_gap(): void
    {
        $now = time();
        $lastHookTs = $now - (CronIntervalEstimator::MIN_SWEEP_GAP - 1);

        // A gap just under the threshold looks like the next feed in the same sweep.
        $result = CronIntervalEstimator::updateEstimate($now, $lastHookTs, 1200);

        $this->assertSame(1200, $result->estimate);
        $this->assertSame($lastHookTs, $result->lastHookTs);
    }

    public function test_cron_interval_estimator_ratchets_up_on_bigger_gap(): void
    {
        $now = time();
        $lastHookTs = $now - 7200;

        // No prior estimate: a new-sweep gap is trusted immediately.
        $result = CronIntervalEstimator::updateEstimate($now, $lastHookTs, 0);

        $this->assertSame(7200, $result->estimate);
        $this->assertSame($now, $result->lastHookTs);
    }

    public function test_cron_interval_estimator_eases_down_on_smaller_gap(): void
    {
        $now = time();
        $lastHookTs = $now - 600;

        // A smaller new-sweep gap (cron sped up) blends in gradually rather than
        // instantly undercutting backoff for erroring feeds.
        $result = CronIntervalEstimator::updateEstimate($now, $lastHookTs, 1200);

        $this->assertSame((int) (0.7 * 1200 + 0.3 * 600), $result->estimate);
        $this->assertSame($now, $result->lastHookTs);
    }

    public function test_cron_interval_estimator_treats_threshold_gap_as_new_sweep(): void
    {
        $now = time();
        $lastHookTs = $now - CronIntervalEstimator::MIN_SWEEP_GAP;

        $result = CronIntervalEstimator::updateEstimate($now, $lastHookTs, 0);

        $this->assertSame(CronIntervalEstimator::MIN_SWEEP_GAP, $result->estimate);
        $this->assertSame($now, $result->lastHookTs);
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
            // Pin to the bootstrap formula explicitly - this test's assertions
            // assume it, and leaving it to whatever init() picked up from disk
            // would make the test depend on cron cadence learned by other tests.
            $ext->cronLastHookTs = 0;
            $ext->cronIntervalEstimate = 0;

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
            // feedBeforeActualizeHook() calls sampleCronInterval(), which persists
            // to user config regardless of the instance-level pin above - reset it
            // so later tests reading init()'s state aren't order-dependent.
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', 0);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', 0);
            FreshRSS_Context::userConf()->save();
        }
    }

    /*
     * Regression test for #57: a feed that has been erroring for a long time,
     * alongside another erroring feed on the same host, must still be retried as
     * soon as its normal adjusted TTL has passed since the last error, so it
     * recovers promptly once the upstream is fixed.
     */
    public function test_feed_before_actualize_does_not_back_off_long_erroring_feeds_sharing_a_host(): void
    {
        $feed = null;
        $sibling = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');
            $sibling = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->defaultTTL = 3600;
            $ext->maxTTL = 86400;
            $ext->minTTL = 0;
            $ext->cronLastHookTs = 0;
            $ext->cronIntervalEstimate = 0;

            // Both feeds last succeeded 40000s ago and have been erroring since,
            // most recently 20000s ago. The average is anchored at the last
            // successful update: only the -16h entry is at or before it, so the
            // adjusted TTL is (57600 - 40000) / 1 = 17600s, which has passed
            // since the last error. Anchoring at the last error instead would
            // stretch it to (57600 - 20000) / 1 = 37600s and keep the feed waiting.
            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            foreach ([$feed, $sibling] as $f) {
                $feedDAO->updateLastUpdate($f->id(), $now - 40000);
                $feedDAO->updateLastError($f->id(), $now - 20000);
            }
            $feed = $feedDAO->searchById($feed->id());

            $result = $ext->feedBeforeActualizeHook($feed);
            $this->assertNotNull($result);
            $this->assertSame($feed->id(), $result->id());
        } finally {
            foreach ([$feed, $sibling] as $f) {
                if ($f !== null) {
                    FreshRSS_feed_Controller::deleteFeed($f->id());
                }
            }
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', 0);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', 0);
            FreshRSS_Context::userConf()->save();
        }
    }

    public function test_feed_before_actualize_skips_feed_paused_by_retry_after(): void
    {
        $url = 'http://wiremock:8080/three_per_day.xml';
        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed($url);

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->maxTTL = 3600;
            $ext->cronLastHookTs = 0;
            $ext->cronIntervalEstimate = 0;

            // Last update a day ago: well past maxTTL, so the feed is due.
            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed->id(), $now - 86400);
            $feed = $feedDAO->searchById($feed->id());

            $this->assertNotNull($ext->feedBeforeActualizeHook($feed));

            // What FreshRSS records itself on a 429/503 response.
            $retryAfter = FreshRSS_http_Util::setRetryAfter($url, '', '120');
            $this->assertGreaterThan($now, $retryAfter);
            $this->assertSame($retryAfter, RetryAfter::forFeed($feed));

            $this->assertNull($ext->feedBeforeActualizeHook($feed));

            // The stats page shows the pause instead of "pending".
            $item = null;
            foreach ($ext->getStats()->getFeedStats(true) as $candidate) {
                if ($candidate->id === $feed->id()) {
                    $item = $candidate;
                }
            }
            $this->assertNotNull($item);
            $this->assertSame($retryAfter, $item->retryAfter);
            $this->assertStringStartsWith(
                'rate limited, retry in ',
                $ext->getStats()->formatTimeUntilNextUpdate($item, $item->baseTTL, $now),
            );
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
            // DATA_PATH is defined by FreshRSS's CLI bootstrap at runtime.
            foreach (glob(constant('DATA_PATH') . '/Retry-After/wiremock*') ?: [] as $file) {
                @unlink($file);
            }
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', 0);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', 0);
            FreshRSS_Context::userConf()->save();
        }
    }

    public function test_feed_before_actualize_respects_min_ttl_floor(): void
    {
        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->defaultTTL = 3600;
            // maxTTL below defaultTTL forces calcAdjustedTTL's escape hatch, so the
            // pre-floor TTL is deterministically defaultTTL (3600s) regardless of
            // the feed's actual entry timing.
            $ext->maxTTL = 100;
            $ext->minTTL = 7200; // simulate a high hidden cache_duration floor

            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed->id(), $now - 5000);
            $feed = $feedDAO->searchById($feed->id());

            // Without the floor, effective TTL would be defaultTTL (3600s) < 5000s
            // elapsed, so the feed would be due. With the 7200s floor in effect,
            // effective TTL becomes 7200s > 5000s elapsed, so it must stay throttled.
            $result = $ext->feedBeforeActualizeHook($feed);
            $this->assertNull($result);
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
        }
    }

    public function test_feed_before_actualize_learns_cron_interval_and_refreshes_an_overdue_feed(): void
    {
        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $now = time();
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', $now - 7200);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', 0);
            FreshRSS_Context::userConf()->save();

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->defaultTTL = 3600;
            // maxTTL below defaultTTL forces calcAdjustedTTL's escape hatch, so the
            // pre-floor TTL is deterministically defaultTTL (3600s) regardless of
            // the feed's actual entry timing.
            $ext->maxTTL = 100;
            $ext->minTTL = 0;

            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed->id(), $now - 5000);
            $feed = $feedDAO->searchById($feed->id());

            // The hook was last invoked 7200s ago (simulating the previous cron
            // sweep), well past MIN_SWEEP_GAP, so this call should detect and
            // learn a ~7200s cron interval. Learning it must not hold the feed
            // back: 5000s have elapsed against a 3600s TTL, and this sweep is
            // the first one at or after that due time, so the feed refreshes
            // now. Snapping it forward to the *next* predicted sweep instead
            // would repeat on every sweep and never let the feed refresh.
            $result = $ext->feedBeforeActualizeHook($feed);
            $this->assertNotNull($result);
            $this->assertSame($feed->id(), $result->id());

            // Allow a few seconds of slack: real wall-clock time elapses between
            // capturing $now above and sampleCronInterval()'s own time() call
            // inside the hook (DB writes, feed re-fetch), so this can't be exact.
            $learnedEstimate = FreshRSS_Context::userConf()->attributeInt('auto_ttl_cron_interval_estimate');
            $this->assertGreaterThanOrEqual(7200, $learnedEstimate);
            $this->assertLessThanOrEqual(7210, $learnedEstimate);
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', 0);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', 0);
            FreshRSS_Context::userConf()->save();
        }
    }

    public function test_feed_before_actualize_min_ttl_floor_holds_then_releases_with_learned_cron(): void
    {
        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->defaultTTL = 3600;
            $ext->maxTTL = 100; // forces the escape hatch, same as above
            $ext->minTTL = 4000; // cache-duration floor alone would allow this feed through

            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $ext->cronIntervalEstimate = 1800;

            // 3800s elapsed: short of the 4000s cache-duration floor, so the feed
            // stays throttled and its due time snaps forward to the next predicted
            // sweep (~now+1800).
            $feedDAO->updateLastUpdate($feed->id(), $now - 3800);
            $feed = $feedDAO->searchById($feed->id());
            $this->assertNull($ext->feedBeforeActualizeHook($feed));

            // 4200s elapsed: past the floor, and this sweep is the first one at or
            // after the resulting due time, so the feed must refresh now. The cron
            // interval only decides which sweep a due time lands on - it can never
            // keep pushing an already-due feed to the sweep after the current one,
            // which would repeat every sweep and stall the feed indefinitely.
            $feedDAO->updateLastUpdate($feed->id(), $now - 4200);
            $feed = $feedDAO->searchById($feed->id());
            $result = $ext->feedBeforeActualizeHook($feed);
            $this->assertNotNull($result);
            $this->assertSame($feed->id(), $result->id());
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', 0);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', 0);
            FreshRSS_Context::userConf()->save();
        }
    }

    public function test_snap_to_next_sweep_is_noop_without_learned_cron_interval(): void
    {
        $stats = new AutoTTLStats(new AutoTTLConfig(3600, 86400, 100));
        $now = time();

        // No cronLastHookTs/cronIntervalEstimate: the value must pass through unchanged.
        $this->assertSame(4650, $stats->snapToNextSweep($now - 4000, 4650));
    }

    public function test_snap_to_next_sweep_is_noop_for_never_attempted_feed_even_with_learned_cron(): void
    {
        $now = time();

        // lastAttempt <= 0 means "never attempted" (calcLastAttempt()) - there is
        // no real anchor to snap against, so even with a learned cron interval the
        // TTL must pass through unchanged rather than snapToNextSweep() returning
        // an absolute predicted-sweep timestamp as if it were a TTL.
        $stats = new AutoTTLStats(new AutoTTLConfig(3600, 86400, 100, 0, $now - 100, 900));

        $this->assertSame(4650, $stats->snapToNextSweep(0, 4650));
    }

    public function test_snap_to_next_sweep_pushes_extra_past_an_already_snapped_base_ttl_to_the_next_sweep(): void
    {
        $now = time();
        $cronLastHookTs = $now - 100;
        $cronIntervalEstimate = 900;

        $stats = new AutoTTLStats(new AutoTTLConfig(3600, 86400, 100, 0, $cronLastHookTs, $cronIntervalEstimate));
        $lastAttempt = $now - 3000;

        // baseTTL already lands exactly on the predicted sweep at now+800 (see
        // test_calc_adjusted_ttl_snaps_forward_to_next_predicted_sweep).
        $baseTTL = $stats->calcAdjustedTTL(1, $lastAttempt);
        $this->assertSame(3800, $baseTTL);

        // Even a small amount of extra time on top has nowhere to land within the
        // current sweep - baseTTL already used it all up - so it must roll
        // forward to the *next* predicted sweep (now+1700), not sit stranded
        // in the gap between the two, which is what would leave the feed
        // showing "pending" again for that whole gap.
        $withExtra = $stats->snapToNextSweep($lastAttempt, $baseTTL + 50);
        $this->assertSame(4700, $withExtra);
        $this->assertSame($cronLastHookTs + 2 * $cronIntervalEstimate, $lastAttempt + $withExtra);
    }

    public function test_snap_to_next_sweep_keeps_any_extra_aligned_to_a_real_sweep(): void
    {
        $now = time();
        $cronLastHookTs = $now - 100;
        $cronIntervalEstimate = 900;

        $stats = new AutoTTLStats(new AutoTTLConfig(3600, 86400, 100, 0, $cronLastHookTs, $cronIntervalEstimate));
        $lastAttempt = $now - 4000;
        $baseTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        // Whatever extra time is added on top of baseTTL, the final due
        // time handed to feedBeforeActualizeHook()/formatTimeUntilNextUpdate()
        // must always land on an actual predicted sweep - i.e. some multiple
        // of cronIntervalEstimate past cronLastHookTs - never in between two.
        for ($extra = 0; $extra < 2000; $extra += 137) {
            $effectiveTTL = $stats->snapToNextSweep($lastAttempt, $baseTTL + $extra);
            $dueTime = $lastAttempt + $effectiveTTL;
            $this->assertSame(0, ($dueTime - $cronLastHookTs) % $cronIntervalEstimate, "misaligned for extra={$extra}");
        }
    }

    public function test_snap_to_next_sweep_never_defers_a_feed_that_is_already_due(): void
    {
        $now = time();
        $cronIntervalEstimate = 900;

        // cronLastHookTs == now is what sampleCronInterval() leaves behind at the
        // start of every sweep. For any feed whose TTL has already elapsed, the
        // snapped TTL must stay within the elapsed time - otherwise
        // feedBeforeActualizeHook()'s `elapsed < ttl` gate skips a feed that is
        // due, and skips it again on the next sweep, and so on forever.
        $stats = new AutoTTLStats(new AutoTTLConfig(3600, 86400, 100, 0, $now, $cronIntervalEstimate));

        for ($overdueBy = 0; $overdueBy <= 3 * $cronIntervalEstimate; $overdueBy += 137) {
            $ttl = 3600;
            $lastAttempt = $now - $ttl - $overdueBy;

            $this->assertLessThanOrEqual(
                $now - $lastAttempt,
                $stats->snapToNextSweep($lastAttempt, $ttl),
                "deferred a feed overdue by {$overdueBy}s"
            );
        }
    }

    public function test_consecutive_sweeps_refresh_a_feed_on_its_own_ttl_cadence(): void
    {
        $defaultTTL = 3600;
        $cronIntervalEstimate = 900;
        $now = time();
        $lastAttempt = $now - 4000;

        // Replays feedBeforeActualizeHook()'s decision once per sweep, moving
        // cronLastHookTs to each sweep's own time the way sampleCronInterval()
        // does. The feed starts out overdue, so it must refresh on the very
        // first sweep and then once per TTL (3600s = 4 sweeps of 900s).
        // Previously every sweep re-snapped the due time one interval further
        // out, so no sweep ever refreshed it while the displayed countdown
        // restarted at one cron interval each time.
        $refreshedAtSweeps = [];
        for ($sweep = 0; $sweep < 12; $sweep++) {
            $sweepTime = $now + $sweep * $cronIntervalEstimate;
            $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, 86400, 100, 0, $sweepTime, $cronIntervalEstimate));

            $ttl = $stats->calcAdjustedTTL(2100, $lastAttempt);
            $effectiveTTL = $stats->snapToNextSweep($lastAttempt, $ttl);

            if ($sweepTime - $lastAttempt >= $effectiveTTL) {
                $refreshedAtSweeps[] = $sweep;
                $lastAttempt = $sweepTime;
            }
        }

        $this->assertSame([0, 4, 8], $refreshedAtSweeps);
    }
}
