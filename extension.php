<?php

require_once __DIR__.'/stats.php';

class AutoTTLExtension extends Minz_Extension
{
    // Defaults
    private const MAX_TTL = 24 * 60 * 60; // 1 day

    private const STATS_COUNT = 100;

    private const ERROR_JITTER = 60 * 60; // 1 hour max jitter for errored feeds

    // FreshRSS_Feed::lastError() legacy-returns 1 (not a real timestamp) for feeds
    // whose error state predates the _feed.error column becoming a BIGINT timestamp.
    public const LEGACY_ERROR_SENTINEL = 1;

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

    public static function calcErrorJitter(int $feedId, int $lastUpdate, int $lastError): int
    {
        if (!self::calcIsErroring($lastUpdate, $lastError) || self::ERROR_JITTER <= 0) {
            return 0;
        }

        return (int) (abs(crc32($feedId . '_' . $lastError)) % self::ERROR_JITTER);
    }

    public function getErrorJitter(FreshRSS_Feed $feed): int
    {
        return self::calcErrorJitter($feed->id(), $feed->lastUpdate(), $feed->lastError());
    }

    /*
     * Whether the feed's most recent fetch attempt ended in an error.
     * Guards against the legacy sentinel value of lastError(), which is not comparable to lastUpdate().
     */
    public static function calcIsErroring(int $lastUpdate, int $lastError): bool
    {
        return $lastError === self::LEGACY_ERROR_SENTINEL || $lastError > $lastUpdate;
    }

    /*
     * Timestamp of the feed's most recent fetch attempt (success or error).
     * Falls back to lastUpdate() when lastError() is the legacy sentinel, since
     * its real timestamp is unknown.
     */
    public static function calcLastAttempt(int $lastUpdate, int $lastError): int
    {
        if ($lastError <= self::LEGACY_ERROR_SENTINEL) {
            return $lastUpdate;
        }

        return max($lastUpdate, $lastError);
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

        $lastAttempt = self::calcLastAttempt($feed->lastUpdate(), $feed->lastError());
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
        $jitter = $this->getErrorJitter($feed);
        $effectiveTTL = $ttl + $jitter;

        if ($timeSinceLastAttempt < $effectiveTTL) {
            Minz_Log::debug(
                sprintf(
                    'AutoTTL: skip feed %d (%s, last attempt %s): effective TTL (%ds = %ds TTL + %ds jitter) not exceeded yet',
                    $feed->id(),
                    $feed->name(),
                    date('r', $lastAttempt),
                    $effectiveTTL,
                    $ttl,
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
                $ttl,
            )
        );

        return $feed;
    }
}
