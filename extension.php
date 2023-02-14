<?php

require_once(__DIR__ . "/stats.php");

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
        $minTTL = $this->getMinTTL($feed);
        $maxTTL = (int)FreshRSS_Context::$user_conf->auto_ttl_max_ttl;
        $d = $now - $feed->lastUpdate();

        if ($d >= $maxTTL) {
            return $feed;
        }

        $avgTTL = $this->stats->calcAvgTTL($feed->id());

        if ($avgTTL === 0) {
            $this->debug($feed, sprintf('unable to calculate avg TTL, falling back to max TTL (%ds)', $maxTTL));
            return null;
        }

        $ttl = $avgTTL;
        if ($ttl > $maxTTL) {
            $ttl = $maxTTL;
        } elseif ($ttl< $minTTL) {
            $ttl = $minTTL;
        }

        if ($d < $ttl) {
            $this->debug($feed, sprintf('adjusted TTL (%ds) not exceeded yet (avg %ds)', $ttl, $avgTTL));
            return null;
        }

        return $feed;
    }

    private function getMinTTL(FreshRSS_Feed $feed): int
    {
        $ttl = $feed->ttl();
        return $ttl == FreshRSS_Feed::TTL_DEFAULT ? FreshRSS_Context::$user_conf->ttl_default : $ttl;
    }

    private function debug(FreshRSS_Feed $feed, string $msg)
    {
        Minz_Log::debug(sprintf(
            'AutoTTL: skip feed %d (%s): %s',
            $feed->id(),
            $feed->name(),
            $msg,
        ));
    }
}
