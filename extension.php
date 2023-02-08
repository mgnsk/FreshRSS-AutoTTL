<?php

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day

    private $statsDAO = null;

    public function init()
    {
        $this->statsDAO = FreshRSS_Factory::createStatsDAO();
        $this->registerHook('feed_before_actualize', array($this, 'feedBeforeActualizeHook'));

        if (is_null(FreshRSS_Context::$user_conf->auto_ttl_max_ttl)) {
            FreshRSS_Context::$user_conf->auto_ttl_max_ttl = self::MAX_TTL;
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function handleConfigureAction()
    {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            FreshRSS_Context::$user_conf->auto_ttl_max_ttl = (int)Minz_Request::param('auto_ttl_max_ttl', self::MAX_TTL);
            FreshRSS_Context::$user_conf->save();
        }
    }

    public function feedBeforeActualizeHook(FreshRSS_Feed $feed)
    {
        $maxTTL = (int)FreshRSS_Context::$user_conf->auto_ttl_max_ttl;

        $ttl = $this->getAvgTTL($feed);
        if ($ttl > $maxTTL) {
            $ttl = $maxTTL;
        }

        $now = time();

        if ($ttl === 0 || $feed->lastUpdate() === 0 || $now - $feed->lastUpdate() > $ttl) {
            return $feed;
        }

        Minz_Log::debug(sprintf(
            'AutoTTL: skipping feed %d (%s), TTL: %ds, last update at %s, next update at %s',
            $feed->id(),
            $feed->name(),
            $ttl,
            date('Y-m-d H:i:s', $feed->lastUpdate()),
            date('Y-m-d H:i:s', $feed->lastUpdate() + $ttl),
        ));

        return null;
    }

    private function getAvgTTL(FreshRSS_Feed $feed): int
    {
        $perHour = $this->statsDAO->calculateEntryAveragePerFeedPerHour($feed->id());

        if ($perHour > 0) {
            // Average seconds between feed entries.
            return (int)((1 / $perHour) * 60 * 60);
        }

        return 0;
    }
}
