<?php

require_once __DIR__.'/stats.php';

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day

    private const STATS_COUNT = 100;

    public int $defaultTTL;

    public int $maxTTL;

    public int $statsCount;

    public int $minTTL;

    public int $cronIntervalEstimate;

    public int $cronLastHookTs;

    /**
     * @var AutoTTLStats
     */
    private $stats;

    public function init()
    {
        parent::init();

        $this->registerHook('feed_before_actualize', [
            $this,
            'feedBeforeActualizeHook',
        ]);
        $this->registerTranslates();

        $this->defaultTTL = FreshRSS_Context::userConf()->attributeInt('ttl_default') ?? FreshRSS_Feed::TTL_DEFAULT;
        $this->maxTTL = FreshRSS_Context::userConf()->attributeInt('auto_ttl_max_ttl') ?? self::MAX_TTL;
        $this->statsCount = FreshRSS_Context::userConf()->attributeInt('auto_ttl_stats_count') ?? self::STATS_COUNT;

        // FreshRSS never actually fetches a feed more often than this, regardless
        // of TTL, so AutoTTL's computed TTL must never claim to be shorter than it.
        $this->minTTL = (int) (FreshRSS_Context::systemConf()->limits['cache_duration'] ?? 0);

        // Internal, self-learned floor: how often the cron/systemd timer running
        // actualize_script.php actually sweeps this user's feeds. FreshRSS has no
        // config value for this - it's timed from our own hook invocations, see
        // sampleCronInterval().
        $this->cronLastHookTs = FreshRSS_Context::userConf()->attributeInt('auto_ttl_cron_last_hook_ts') ?? 0;
        $this->cronIntervalEstimate = FreshRSS_Context::userConf()->attributeInt('auto_ttl_cron_interval_estimate') ?? 0;
    }

    /*
     * Called by FreshRSS when the configuration page is loaded or saved.
     */
    public function handleConfigureAction()
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::userConf()->_attribute('auto_ttl_max_ttl', Minz_Request::paramInt('auto_ttl_max_ttl'));
            FreshRSS_Context::userConf()->_attribute('auto_ttl_stats_count', Minz_Request::paramInt('auto_ttl_stats_count'));
            FreshRSS_Context::userConf()->save();
        }
    }

    public function getStats(): AutoTTLStats
    {
        if ($this->stats === null) {
            $this->stats = new AutoTTLStats($this->defaultTTL, $this->maxTTL, $this->statsCount, $this->minTTL, $this->cronLastHookTs, $this->cronIntervalEstimate);
        }

        return $this->stats;
    }

    /*
     * Times this hook's own invocations to learn how often actualize_script.php
     * actually sweeps this user's feeds, since FreshRSS exposes no config value
     * for its own cron/systemd cadence. See CronIntervalEstimator for the logic.
     */
    private function sampleCronInterval(): void
    {
        $now = time();
        $result = CronIntervalEstimator::updateEstimate($now, $this->cronLastHookTs, $this->cronIntervalEstimate);

        if ($result['lastHookTs'] !== $this->cronLastHookTs) {
            // Only touches disk when a new sweep was actually detected (or on
            // this user's very first-ever sample) - not once per feed.
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_last_hook_ts', $result['lastHookTs']);
            FreshRSS_Context::userConf()->_attribute('auto_ttl_cron_interval_estimate', $result['estimate']);
            FreshRSS_Context::userConf()->save();
        }

        $this->cronLastHookTs = $result['lastHookTs'];
        $this->cronIntervalEstimate = $result['estimate'];
    }

    public function getBackoffTTL(FreshRSS_Feed $feed, int $baseTTL): int
    {
        $lastAttempt = StatItem::calcLastAttempt($feed->lastUpdate(), $feed->lastError());
        $isErroring = StatItem::calcIsErroring($feed->lastUpdate(), $feed->lastError());

        // Only touches the host-group query when it can matter: an erroring
        // feed. A healthy sweep never pays for it.
        $groupInfo = ['rank' => 0, 'size' => 1, 'host' => ''];
        if ($isErroring) {
            $groupInfo = $this->getStats()->getGroupInfoForFeed($feed->id());
        }

        return StatItem::calcBackoffTTL(
            $baseTTL, $feed->lastUpdate(), $lastAttempt,
            $isErroring, $this->maxTTL, $this->cronIntervalEstimate,
            $groupInfo['rank'], $groupInfo['size'], $groupInfo['host']
        );
    }

    public function feedBeforeActualizeHook(FreshRSS_Feed $feed)
    {
        // A direct request for one feed is a user-initiated refresh.
        // Do not let AutoTTL suppress it.
        if (
            Minz_Request::controllerName() === 'feed' &&
            Minz_Request::actionName() === 'actualize' &&
            Minz_Request::paramInt('id') === $feed->id()
        ) {
            return $feed;
        }

        $this->sampleCronInterval();

        $lastAttempt = StatItem::calcLastAttempt($feed->lastUpdate(), $feed->lastError());
        if ($lastAttempt === 0) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: feed %d (%s) never attempted, updating now',
                    $feed->id(),
                    $feed->name(),
                )
            );

            return $feed;
        }

        if ($feed->ttl() !== FreshRSS_Feed::TTL_DEFAULT) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: feed %d (%s) not using default TTL, updating now',
                    $feed->id(),
                    $feed->name(),
                )
            );

            return $feed;
        }

        $timeSinceLastAttempt = time() - $lastAttempt;
        $ttl = $this->getStats()->getAdjustedTTL($feed->id(), $lastAttempt);
        $backoffTTL = $this->getBackoffTTL($feed, $ttl);
        // $ttl already lands on a predicted cron sweep, and so does $backoffTTL
        // when the cron interval is known - but the bootstrap backoff
        // formula (interval not yet learned) doesn't; re-snap so the gate below
        // always resolves at an actual sweep instead of sometime in the gap
        // before the next one.
        $effectiveTTL = $this->getStats()->snapToNextSweep($lastAttempt, $backoffTTL);

        if ($timeSinceLastAttempt < $effectiveTTL) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: skip feed %d (%s, last attempt %s): effective TTL (%ds, from %ds TTL + %ds backoff) not exceeded yet',
                    $feed->id(),
                    $feed->name(),
                    date('r', $lastAttempt),
                    $effectiveTTL,
                    $ttl,
                    $backoffTTL - $ttl,
                )
            );

            return null;
        }

        Minz_Log::debug(
            sprintf(
                'AutoTTL: updating feed %d (%s, last attempt %s, backoff TTL %ds)',
                $feed->id(),
                $feed->name(),
                date('r', $lastAttempt),
                $backoffTTL,
            )
        );

        return $feed;
    }
}
