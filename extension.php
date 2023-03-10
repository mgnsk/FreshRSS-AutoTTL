<?php

require_once __DIR__ . "/stats.php";

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day

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
    }

    public function handleConfigureAction()
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::$user_conf->auto_ttl_max_ttl = (int) Minz_Request::param(
                'auto_ttl_max_ttl',
                self::MAX_TTL
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
            return $feed;
        }

        $timeSinceLastUpdate = time() - $feed->lastUpdate();
        $ttl = $this->stats->getAdjustedTTL($feed);

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

        return $feed;
    }
}
