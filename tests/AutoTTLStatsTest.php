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
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now);

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
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now - 172798);
            $this->assertSame($defaultTTL, $adjustedTTL);

            // Two updates in a row, but hours ago, implies moderate updates.
            // (-115200 - -172800) / 2 = 57600 seconds / 2 = 28800 seconds
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now - 115200);
            $this->assertSame(28800, $adjustedTTL);

            // Two updates in a row, but days ago, implies slow updates.
            // 2 days > 1 day $maxTTL
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), $now);
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
            $adjustedTTL = $stats->getAdjustedTTL($feed->id(), time());

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

    public function test_get_group_info_for_feed_groups_by_exact_host(): void
    {
        $defaultTTL = 3600;
        $maxTTL = 86400;

        $feed1 = null;
        $feed2 = null;
        $feed3 = null;
        $customTTLFeed = null;
        try {
            $feed1 = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');
            $feed2 = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');
            $feed3 = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/future_dated.xml');
            $customTTLFeed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml?custom_ttl');

            $feedDAO = FreshRSS_Factory::createFeedDao();
            $now = time();

            foreach ([$feed1, $feed2, $feed3, $customTTLFeed] as $feed) {
                $feedDAO->updateLastUpdate($feed->id(), $now - 86400);
                $feedDAO->updateLastError($feed->id(), $now - 600);
            }
            $feedDAO->updateFeed($customTTLFeed->id(), ['ttl' => 1800]);

            $stats = new AutoTTLStats(new AutoTTLConfig($defaultTTL, $maxTTL, 100));

            // All three wiremock:8080 feeds share the same exact host, so they
            // must be grouped together with distinct ranks...
            $info1 = $stats->getGroupInfoForFeed($feed1->id());
            $info2 = $stats->getGroupInfoForFeed($feed2->id());
            $info3 = $stats->getGroupInfoForFeed($feed3->id());

            $this->assertSame('wiremock', $info1->host);
            $this->assertSame(3, $info1->size);
            $this->assertSame(3, $info2->size);
            $this->assertSame(3, $info3->size);

            $ranks = [$info1->rank, $info2->rank, $info3->rank];
            sort($ranks);
            $this->assertSame([0, 1, 2], $ranks);

            // ...but a feed with a custom (non-AutoTTL) TTL must be excluded,
            // even though it's also erroring - it returns the ungrouped default.
            $customInfo = $stats->getGroupInfoForFeed($customTTLFeed->id());
            $this->assertSame(1, $customInfo->size);
            $this->assertSame('', $customInfo->host);
        } finally {
            foreach ([$feed1, $feed2, $feed3, $customTTLFeed] as $feed) {
                if ($feed !== null) {
                    FreshRSS_feed_Controller::deleteFeed($feed->id());
                }
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

        $this->assertSame(1000, $item1->attempt->lastUpdate);
        $this->assertSame(500, $item1->lastError);
        $this->assertSame(1000, $item1->attempt->lastAttempt);
        $this->assertFalse($item1->attempt->isErroring);
        $this->assertSame($baseTTL, $item1->baseTTL);
        $this->assertSame($baseTTL, $item1->backoffTTL);

        // items 2-4 below pass groupSize=2 - back-off only ever applies to a
        // feed sharing a host with another currently-erroring feed (see
        // calcBackoffTTL's groupSize gate), so without it these would all
        // resolve to baseTTL regardless of errorAge.

        // errorAge (1000s) below baseTTL (3600s): backoff stays at baseTTL.
        $item2 = new StatItem([
            'id' => 2,
            'name' => 'Errored Feed',
            'lastUpdate' => 1000,
            'error' => 2000,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL, 0, new ErrorGroupInfo(0, 2));

        $this->assertSame(1000, $item2->attempt->lastUpdate);
        $this->assertSame(2000, $item2->lastError);
        $this->assertSame(2000, $item2->attempt->lastAttempt);
        $this->assertTrue($item2->attempt->isErroring);
        $this->assertSame($baseTTL, $item2->baseTTL);
        $this->assertSame($baseTTL, $item2->backoffTTL);

        // errorAge (7200s) above baseTTL: backoff grows to match errorAge.
        $item3 = new StatItem([
            'id' => 3,
            'name' => 'Long Errored Feed',
            'lastUpdate' => 1000,
            'error' => 8200,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL, 0, new ErrorGroupInfo(0, 2));

        $this->assertSame($baseTTL, $item3->baseTTL);
        $this->assertSame(7200, $item3->backoffTTL);

        // errorAge far exceeding maxTTL: backoff clamps at maxTTL.
        $item4 = new StatItem([
            'id' => 4,
            'name' => 'Deeply Errored Feed',
            'lastUpdate' => 1000,
            'error' => 1000 + 200000,
            'ttl' => 0,
            'avgTTL' => 3600,
        ], $baseTTL, $maxTTL, 0, new ErrorGroupInfo(0, 2));

        $this->assertSame($maxTTL, $item4->backoffTTL);
    }

    public function test_calc_backoff_ttl_grows_and_clamps(): void
    {
        $baseTTL = 3600;
        $maxTTL = 86400;
        $lastUpdate = 0;
        // groupSize > 1: back-off only ever applies to a feed sharing a host
        // with another currently-erroring feed - see
        // test_calc_backoff_ttl_solo_feed_returns_base_ttl for the groupSize
        // <= 1 case.
        $groupSize = 2;

        // Not erroring: backoff is always just baseTTL.
        $this->assertSame($baseTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, 100000, false), $maxTTL, 0, new ErrorGroupInfo(0, $groupSize)));

        // errorAge below baseTTL: clamped up to baseTTL.
        $this->assertSame($baseTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, 1800, true), $maxTTL, 0, new ErrorGroupInfo(0, $groupSize)));

        // Simulate consecutive throttled retries: each retry only happens after
        // waiting the previous backoffTTL, so errorAge grows by that amount each
        // pass. Once past baseTTL, this produces roughly-doubling backoff.
        $errorAge = $baseTTL;
        $backoffs = [];
        for ($i = 0; $i < 5; $i++) {
            $backoff = BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, $errorAge, true), $maxTTL, 0, new ErrorGroupInfo(0, $groupSize));
            $backoffs[] = $backoff;
            $errorAge += $backoff;
        }

        $this->assertSame([3600, 7200, 14400, 28800, 57600], $backoffs);

        // Clamped at maxTTL however large errorAge grows.
        $this->assertSame($maxTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, $maxTTL * 10, true), $maxTTL, 0, new ErrorGroupInfo(0, $groupSize)));
    }

    public function test_calc_backoff_ttl_solo_feed_returns_base_ttl(): void
    {
        // groupSize <= 1 (the default) means no other AutoTTL-managed feed is
        // currently erroring on the same host - back-off only exists to spread
        // out a shared-host pile-up, so a lone erroring feed must always
        // resolve to baseTTL, however long it's been failing.
        $baseTTL = 3600;
        $maxTTL = 86400;
        $lastUpdate = 0;

        $this->assertSame($baseTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, $maxTTL * 10, true), $maxTTL));

        // Also holds on the cron-aware path (cronInterval > 0).
        $this->assertSame($baseTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, $maxTTL * 10, true), $maxTTL, 1800));
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
        $groupSize = 2;

        $this->assertSame($baseTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, 100, true), $maxTTL, 0, new ErrorGroupInfo(0, $groupSize)));
        $this->assertSame($baseTTL, BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastUpdate, $baseTTL * 10, true), $maxTTL, 0, new ErrorGroupInfo(0, $groupSize)));
    }

    public function test_calc_skip_sweeps_range_grows_and_clamps(): void
    {
        $cronInterval = 900;
        $lastUpdate = 0;
        $groupSize = 5;

        // Fresh error (errorAge below one sweep): range floors at MIN_SKIP_SWEEPS
        // (bumped to fit groupSize), and every slot is always at least 1 sweep -
        // never eager on its own error.
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $skip = BackoffCalculator::calcSkipSweeps(new FeedAttempt($lastUpdate, 100), $cronInterval, 1000, new ErrorGroupInfo($rank, $groupSize, 'example.com'));
            $this->assertGreaterThanOrEqual(1, $skip);
        }

        // Long-erroring group: the range grows well past MIN_SKIP_SWEEPS, so the
        // draw can exceed it too.
        $skip = BackoffCalculator::calcSkipSweeps(new FeedAttempt($lastUpdate, 20 * $cronInterval), $cronInterval, 1000, new ErrorGroupInfo(0, $groupSize, 'example.com'));
        $this->assertGreaterThanOrEqual(1, $skip);
        $this->assertLessThanOrEqual(20, $skip);

        // Clamped at maxSweeps however large errorAge grows - even when
        // MIN_SKIP_SWEEPS/groupSize alone would otherwise exceed maxSweeps (a
        // coarse cron relative to maxTTL), preserving the same eventual-retry
        // cap maxTTL provides today.
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $skip = BackoffCalculator::calcSkipSweeps(new FeedAttempt($lastUpdate, 10_000_000), $cronInterval, 3, new ErrorGroupInfo($rank, $groupSize, 'example.com'));
            $this->assertGreaterThanOrEqual(1, $skip);
            $this->assertLessThanOrEqual(3, $skip);
        }
    }

    public function test_calc_skip_sweeps_is_deterministic(): void
    {
        $cronInterval = 900;

        $a = BackoffCalculator::calcSkipSweeps(new FeedAttempt(0, 5000), $cronInterval, 1000, new ErrorGroupInfo(2, 5, 'example.com'));
        $b = BackoffCalculator::calcSkipSweeps(new FeedAttempt(0, 5000), $cronInterval, 1000, new ErrorGroupInfo(2, 5, 'example.com'));

        $this->assertSame($a, $b);
    }

    public function test_calc_skip_sweeps_same_host_group_gets_distinct_slots(): void
    {
        $cronInterval = 900;
        $groupSize = 5;

        $skips = [];
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $skips[] = BackoffCalculator::calcSkipSweeps(new FeedAttempt(0, 100), $cronInterval, 1000, new ErrorGroupInfo($rank, $groupSize, 'www.youtube.com'));
        }

        $this->assertCount($groupSize, array_unique($skips), 'Expected every member of a same-host group to get a distinct slot');
    }

    public function test_calc_skip_sweeps_different_hosts_dont_all_collide_at_rank_zero(): void
    {
        $cronInterval = 900;

        $skips = [];
        foreach (['www.youtube.com', 'example.org', 'feeds.example.net', 'podcasts.example.com', 'news.example.io'] as $host) {
            $skips[] = BackoffCalculator::calcSkipSweeps(new FeedAttempt(0, 100), $cronInterval, 1000, new ErrorGroupInfo(0, 3, $host));
        }

        $this->assertGreaterThan(1, count(array_unique($skips)), 'Expected the rank-0 slot to vary across different host groups');
    }

    public function test_calc_skip_sweeps_group_larger_than_max_sweeps_wraps_without_exceeding_it(): void
    {
        $cronInterval = 900;
        $maxSweeps = 3;
        $groupSize = 10;

        for ($rank = 0; $rank < $groupSize; $rank++) {
            $skip = BackoffCalculator::calcSkipSweeps(new FeedAttempt(0, 100), $cronInterval, $maxSweeps, new ErrorGroupInfo($rank, $groupSize, 'www.youtube.com'));
            $this->assertGreaterThanOrEqual(1, $skip);
            $this->assertLessThanOrEqual($maxSweeps, $skip);
        }
    }

    public function test_calc_skip_sweeps_group_bumps_range_past_min_skip_sweeps(): void
    {
        $cronInterval = 900;
        $groupSize = 10; // > MIN_SKIP_SWEEPS (4)

        // Fresh error (errorAge below one sweep): the ungrouped range would
        // floor at MIN_SKIP_SWEEPS, which isn't enough slots for 10 feeds to
        // disperse into without collisions.
        $skips = [];
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $skips[] = BackoffCalculator::calcSkipSweeps(new FeedAttempt(0, 100), $cronInterval, 1000, new ErrorGroupInfo($rank, $groupSize, 'www.youtube.com'));
        }

        $this->assertCount($groupSize, array_unique($skips));
        $this->assertGreaterThan(BackoffCalculator::MIN_SKIP_SWEEPS, max($skips), 'Expected the range to bump past MIN_SKIP_SWEEPS to fit the whole group');
    }

    public function test_calc_backoff_ttl_cron_aware_grows_and_clamps(): void
    {
        $baseTTL = 3600;
        $cronInterval = 900;
        $maxTTL = 86400;
        $groupSize = 3; // <= MIN_SKIP_SWEEPS, so the slot range isn't bumped past it

        // Fresh error: backoffTTL is baseTTL plus a random 0..(MIN_SKIP_SWEEPS-1)
        // extra sweeps.
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $backoffTTL = BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt(0, 100, true), $maxTTL, $cronInterval, new ErrorGroupInfo($rank, $groupSize, 'example.com'));
            $this->assertGreaterThanOrEqual($baseTTL, $backoffTTL);
            $this->assertLessThanOrEqual($baseTTL + (BackoffCalculator::MIN_SKIP_SWEEPS - 1) * $cronInterval, $backoffTTL);
        }

        // Clamped at maxTTL however large errorAge grows.
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $backoffTTL = BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt(0, $maxTTL * 10, true), $maxTTL, $cronInterval, new ErrorGroupInfo($rank, $groupSize, 'example.com'));
            $this->assertLessThanOrEqual($maxTTL, $backoffTTL);
        }
    }

    public function test_calc_backoff_ttl_cron_aware_never_exceeds_max_ttl_when_base_ttl_is_large(): void
    {
        // baseTTL close to maxTTL leaves little headroom for extra sweeps -
        // budgeting maxSweeps from maxTTL alone (ignoring baseTTL) would let
        // the result overshoot maxTTL.
        $baseTTL = 80000;
        $cronInterval = 900;
        $maxTTL = 86400;
        $groupSize = 3;

        for ($rank = 0; $rank < $groupSize; $rank++) {
            $backoffTTL = BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt(0, $maxTTL * 10, true), $maxTTL, $cronInterval, new ErrorGroupInfo($rank, $groupSize, 'example.com'));
            $this->assertGreaterThanOrEqual($baseTTL, $backoffTTL);
            $this->assertLessThanOrEqual($maxTTL, $backoffTTL);
        }
    }

    public function test_calc_backoff_ttl_spreads_shared_rate_limit_event_across_multiple_sweeps(): void
    {
        // Feeds hit by a shared rate-limit event at the same instant must not
        // all land back on the very next predicted sweep - the whole-sweep
        // skip range must actually spread them across multiple sweeps, even
        // when baseTTL is smaller than one cron interval and the cron is coarse.
        $now = time();
        $cronLastHookTs = $now - 100;
        $cronIntervalEstimate = 1200; // 20-minute cron
        $baseTTL = 300; // smaller than one cron interval
        $maxTTL = 86400;
        $groupSize = 30;

        $stats = new AutoTTLStats(new AutoTTLConfig(3600, $maxTTL, 100, 0, $cronLastHookTs, $cronIntervalEstimate));
        $lastAttempt = $now - $baseTTL; // due right about now, before backoff pushes it out

        $dueSweeps = [];
        for ($rank = 0; $rank < $groupSize; $rank++) {
            $backoffTTL = BackoffCalculator::calcBackoffTTL($baseTTL, new FeedAttempt($lastAttempt - 1, $lastAttempt, true), $maxTTL, $cronIntervalEstimate, new ErrorGroupInfo($rank, $groupSize, 'example.com'));
            $effectiveTTL = $stats->snapToNextSweep($lastAttempt, $backoffTTL);
            $dueSweeps[] = $lastAttempt + $effectiveTTL;
        }

        $this->assertGreaterThan(1, count(array_unique($dueSweeps)), 'Expected feeds to spread across more than one predicted sweep');
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

    public function test_get_backoff_ttl_no_extra_spread_without_learned_cron_interval(): void
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
            $ext->cronIntervalEstimate = 0; // bootstrap: cadence not learned yet

            $now = time();
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed1->id(), $now - 86400);
            $feedDAO->updateLastUpdate($feed2->id(), $now - 86400);

            $errorTime = $now - 600;
            $feedDAO->updateLastError($feed1->id(), $errorTime);
            $feedDAO->updateLastError($feed2->id(), $errorTime);

            $feed1Error = $feedDAO->searchById($feed1->id());
            $feed2Error = $feedDAO->searchById($feed2->id());

            $baseTTL = 3600;

            // No per-feed randomization is available yet (see calcBackoffTTL's
            // bootstrap fallback) - two feeds erroring identically at the same
            // instant must resolve to the exact same backoffTTL.
            $this->assertSame($ext->getBackoffTTL($feed1Error, $baseTTL), $ext->getBackoffTTL($feed2Error, $baseTTL));
        } finally {
            if ($feed1 !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed1->id());
            }
            if ($feed2 !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed2->id());
            }
        }
    }

    public function test_get_backoff_ttl_is_deterministic_per_feed(): void
    {
        // Staggering across feeds sharing a host is covered directly by
        // test_calc_skip_sweeps_same_host_group_gets_distinct_slots and
        // test_get_backoff_ttl_coordinates_feeds_sharing_a_host below; this
        // test just confirms getBackoffTTL() itself is stable across repeated
        // calls for the same feed state.
        $feed = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->cronLastHookTs = time() - 500;
            $ext->cronIntervalEstimate = 900;

            $now = time();
            $errorTime = $now - 600;
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feedDAO->updateLastUpdate($feed->id(), $now - 86400);
            $feedDAO->updateLastError($feed->id(), $errorTime);
            $feed = $feedDAO->searchById($feed->id());

            $baseTTL = 3600;

            $backoff = $ext->getBackoffTTL($feed, $baseTTL);
            $this->assertSame($backoff, $ext->getBackoffTTL($feed, $baseTTL));
        } finally {
            if ($feed !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed->id());
            }
        }
    }

    public function test_get_backoff_ttl_coordinates_feeds_sharing_a_host(): void
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
            $ext->cronLastHookTs = time() - 500;
            $ext->cronIntervalEstimate = 900;

            $now = time();
            $errorTime = $now - 600;
            $feedDAO = FreshRSS_Factory::createFeedDao();
            foreach ([$feed1, $feed2] as $feed) {
                $feedDAO->updateLastUpdate($feed->id(), $now - 86400);
                $feedDAO->updateLastError($feed->id(), $errorTime);
            }
            $feed1 = $feedDAO->searchById($feed1->id());
            $feed2 = $feedDAO->searchById($feed2->id());

            $baseTTL = 3600;

            // Both feeds share the wiremock:8080 host and error at the exact
            // same instant - host-group coordination must give them distinct
            // backoffTTLs, instead of leaving it to independent per-feed hash
            // luck (which could collide).
            $this->assertNotSame($ext->getBackoffTTL($feed1, $baseTTL), $ext->getBackoffTTL($feed2, $baseTTL));
        } finally {
            if ($feed1 !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed1->id());
            }
            if ($feed2 !== null) {
                FreshRSS_feed_Controller::deleteFeed($feed2->id());
            }
        }
    }

    public function test_feed_before_actualize_effective_ttl_aligned_to_sweep_grid_when_learned(): void
    {
        $feed = null;
        $sibling = null;
        try {
            $feed = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/three_per_day.xml');
            // A second feed sharing the same host and erroring at the same
            // instant forms a real back-off group, so calcSkipSweeps() actually
            // adds whole-sweep skips on top of baseTTL below - without a
            // sibling, groupSize stays 1 and back-off never applies (see
            // BackoffCalculator::calcBackoffTTL).
            $sibling = FreshRSS_feed_Controller::addFeed('http://wiremock:8080/two_close.xml');

            $metaInfo = json_decode((string) file_get_contents(dirname(__DIR__) . '/metadata.json'), true);
            $metaInfo['path'] = dirname(__DIR__);
            $ext = new AutoTTLExtension($metaInfo);
            $ext->init();
            $ext->defaultTTL = 3600;
            $ext->maxTTL = 86400;
            $ext->minTTL = 0;

            $now = time();
            $ext->cronLastHookTs = $now - 500;
            $ext->cronIntervalEstimate = 900;

            $feedDAO = FreshRSS_Factory::createFeedDao();
            // lastUpdate far enough back, and a more recent lastError, that both
            // feeds are erroring - so the random skip applies on top of the base TTL.
            foreach ([$feed, $sibling] as $f) {
                $feedDAO->updateLastUpdate($f->id(), $now - 86400);
                $feedDAO->updateLastError($f->id(), $now - 5000);
            }
            $feed = $feedDAO->searchById($feed->id());

            // Whatever skip sweeps resolve to, getBackoffTTL() must land exactly
            // on the predicted sweep grid (cronLastHookTs + n*cronIntervalEstimate)
            // by construction - not merely after a corrective snapToNextSweep() pass.
            $lastAttempt = FeedAttempt::calcLastAttempt($feed->lastUpdate(), $feed->lastError());
            $ttl = $ext->getStats()->getAdjustedTTL($feed->id(), $lastAttempt);
            $backoffTTL = $ext->getBackoffTTL($feed, $ttl);

            $dueTime = $lastAttempt + $backoffTTL;
            $this->assertSame(0, ($dueTime - $ext->cronLastHookTs) % $ext->cronIntervalEstimate);
        } finally {
            foreach ([$feed, $sibling] as $f) {
                if ($f !== null) {
                    FreshRSS_feed_Controller::deleteFeed($f->id());
                }
            }
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

    public function test_snap_to_next_sweep_keeps_backoff_aligned_to_a_real_sweep(): void
    {
        $now = time();
        $cronLastHookTs = $now - 100;
        $cronIntervalEstimate = 900;

        $stats = new AutoTTLStats(new AutoTTLConfig(3600, 86400, 100, 0, $cronLastHookTs, $cronIntervalEstimate));
        $lastAttempt = $now - 4000;
        $baseTTL = $stats->calcAdjustedTTL(1, $lastAttempt);

        // Whatever error backoff growth adds on top of baseTTL, the final due
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
