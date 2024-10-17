<?php

require_once __DIR__ . "/stats.php";

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day
    private const STATS_COUNT = 100;

    /**
     * @var AutoTTLStats
     */
    private $stats;

    public function init()
    {
        $this->stats = new AutoTTLStats();
        $this->registerHook('feed_before_actualize', [
            $this,
            'feedBeforeActualizeHook',
        ]);

        if (is_null(FreshRSS_Context::$user_conf->auto_ttl_max_ttl)) {
            FreshRSS_Context::$user_conf->auto_ttl_max_ttl = self::MAX_TTL;
            FreshRSS_Context::$user_conf->save();
        }

        if (is_null(FreshRSS_Context::$user_conf->auto_ttl_stats_count)) {
            FreshRSS_Context::$user_conf->auto_ttl_stats_count = self::STATS_COUNT;
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function handleConfigureAction()
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::$user_conf->auto_ttl_max_ttl = (int) Minz_Request::param(
                'auto_ttl_max_ttl',
                self::MAX_TTL
            );
            FreshRSS_Context::$user_conf->auto_ttl_stats_count = (int) Minz_Request::param(
                'auto_ttl_stats_count',
                self::STATS_COUNT
            );
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function getStats(): AutoTTLStats
    {
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
        $ttl = $this->stats->getAdjustedTTL($feed->id());

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
