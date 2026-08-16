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
            $this->stats = new AutoTTLStats($this->defaultTTL, $this->maxTTL, $this->statsCount);
        }

        return $this->stats;
    }

    public function getBackoffTTL(FreshRSS_Feed $feed, int $baseTTL): int
    {
        $lastAttempt = StatItem::calcLastAttempt($feed->lastUpdate(), $feed->lastError());
        $isErroring = StatItem::calcIsErroring($feed->lastUpdate(), $feed->lastError());

        return StatItem::calcBackoffTTL($baseTTL, $feed->lastUpdate(), $lastAttempt, $isErroring, $this->maxTTL);
    }

    public function getErrorJitter(FreshRSS_Feed $feed, int $backoffTTL): int
    {
        $isErroring = StatItem::calcIsErroring($feed->lastUpdate(), $feed->lastError());

        return StatItem::calcErrorJitter($feed->id(), $backoffTTL, $feed->lastError(), $isErroring);
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
        $jitter = $this->getErrorJitter($feed, $backoffTTL);
        $effectiveTTL = $backoffTTL + $jitter;

        if ($timeSinceLastAttempt < $effectiveTTL) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: skip feed %d (%s, last attempt %s): effective TTL (%ds = %ds TTL + %ds backoff + %ds jitter) not exceeded yet',
                    $feed->id(),
                    $feed->name(),
                    date('r', $lastAttempt),
                    $effectiveTTL,
                    $ttl,
                    $backoffTTL - $ttl,
                    $jitter,
                )
            );

            return null;
        }

        Minz_Log::debug(
            sprintf(
                'AutoTTL: updating feed %d (%s, last attempt %s, adjusted TTL %ds)',
                $feed->id(),
                $feed->name(),
                date('r', $lastAttempt),
                $backoffTTL,
            )
        );

        return $feed;
    }
}
