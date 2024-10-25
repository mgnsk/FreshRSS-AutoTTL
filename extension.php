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
        $this->initConfig();
    }

    private function initConfig()
    {
        if (!FreshRSS_Context::userConf()->hasParam('auto_ttl_max_ttl')) {
            FreshRSS_Context::userConf()->_attribute('auto_ttl_max_ttl', self::MAX_TTL);
        }

        if (!FreshRSS_Context::userConf()->hasParam('auto_ttl_stats_count')) {
            FreshRSS_Context::userConf()->_attribute('auto_ttl_stats_count', self::STATS_COUNT);
        }

        FreshRSS_Context::userConf()->save();

        $this->defaultTTL = FreshRSS_Context::userConf()->attributeInt('ttl_default');
        $this->maxTTL = FreshRSS_Context::userConf()->attributeInt('auto_ttl_max_ttl');
        $this->statsCount = FreshRSS_Context::userConf()->attributeInt('auto_ttl_stats_count');
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

    public function feedBeforeActualizeHook(FreshRSS_Feed $feed)
    {
        if ($feed->lastUpdate() === 0) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: feed %d (%s) never updated, updating now',
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

        $timeSinceLastUpdate = time() - $feed->lastUpdate();
        $ttl = $this->getStats()->getAdjustedTTL($feed->id());

        if ($timeSinceLastUpdate < $ttl) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: skip feed %d (%s, last update %s): adjusted TTL (%ds) not exceeded yet',
                    $feed->id(),
                    $feed->name(),
                    date('r', $feed->lastUpdate()),
                    $ttl,
                )
            );

            return null;
        }

        Minz_Log::debug(
            sprintf(
                'AutoTTL: updating feed %d (%s, last update %s, adjusted TTL %ds)',
                $feed->id(),
                $feed->name(),
                date('r', $feed->lastUpdate()),
                $ttl,
            )
        );

        return $feed;
    }
}
