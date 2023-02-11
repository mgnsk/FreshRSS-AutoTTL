<?php

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day

    /**
     * @var FreshRSS_StatsDAO
     */
    private $statsDAO = null;

    /**
     * @var FreshRSS_FeedDAO
     */
    private $feedDAO = null;

    public function init()
    {
        $this->statsDAO = FreshRSS_Factory::createStatsDAO();
        $this->feedDAO = FreshRSS_Factory::createFeedDao();
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
        if ($feed->lastUpdate() === 0) {
            return $feed;
        }

        $now = time();
        $maxTTL = (int)FreshRSS_Context::$user_conf->auto_ttl_max_ttl;
        $count = $this->feedDAO->countEntries($feed->id());

        if ($count < 2) {
            if ($now - $feed->lastUpdate() > $maxTTL) {
                return $feed;
            }

            $this->debug("unable to calculate avg, falling back to maxTTL", $feed, $count, $maxTTL);

            return null;
        }

        // Calculate average seconds between feed entries.
        $perHour = $this->statsDAO->calculateEntryAveragePerFeedPerHour($feed->id());

        if ((int)$perHour === (int)$count) {
            // All entries have the same date. May happen with XPath scraping when date selector is not specified.
            if ($now - $feed->lastUpdate() > $maxTTL) {
                return $feed;
            }

            $this->debug("all entries have the same date, falling back to maxTTL", $feed, $count, $maxTTL);

            return null;
        }

        $ttl = (int)((1 / $perHour) * 60 * 60);
        if ($ttl > $maxTTL) {
            $ttl = $maxTTL;
        }

        if ($now - $feed->lastUpdate() > $ttl) {
            return $feed;
        }

        $this->debug("avg TTL not exceeded yet", $feed, $count, $ttl);

        return null;
    }

    private function debug(string $msg, FreshRSS_Feed $feed, int $count, int $ttl)
    {
        Minz_Log::debug(sprintf(
            'AutoTTL: skip feed %d (%s), %s, count: %d, TTL: %ds, last update at %s, next update at %s',
            $feed->id(),
            $feed->name(),
            $msg,
            $count,
            $ttl,
            date('Y-m-d H:i:s', $feed->lastUpdate()),
            date('Y-m-d H:i:s', $feed->lastUpdate() + $ttl),
        ));
    }
}
