<?php

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day


    /**
     * @var FreshRSS_StatsDAO
     */
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
        $now = time();
        
        $maxTTL = (int)FreshRSS_Context::$user_conf->auto_ttl_max_ttl;
        
        if ($this->countEntries($feed) < 2){
            Minz_Log::debug(sprintf(
                'AutoTTL: %d entries in feed %d (%s), unable to calculate avg TTL ,fallbacking to max TTL',
                $this->countEntries($feed),
                $feed->id(),
                $feed->name(),
            ));
            
            if ($now - $feed->lastUpdate() < $maxTTL) {
                return null;
            } else {
                return $feed;
            }
        }

        $ttl = $this->getAvgTTL($feed);
        if ($ttl > $maxTTL) {
            $ttl = $maxTTL;
        }

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

    private function countEntries(FreshRSS_Feed $feed): int
    {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        return (int)$feedEntries = $feedDAO->countEntries($feed->id());
    }
}
