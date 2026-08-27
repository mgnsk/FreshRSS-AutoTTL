<?php

final class FeedAttempt
{
    // FreshRSS_Feed::lastError() legacy-returns 1 (not a real timestamp) for feeds
    // whose error state predates the _feed.error column becoming a BIGINT timestamp.
    public const LEGACY_ERROR_SENTINEL = 1;

    public function __construct(
        public readonly int $lastUpdate,
        public readonly int $lastAttempt,
        public readonly bool $isErroring = false,
    ) {
    }

    public static function fromTimestamps(int $lastUpdate, int $lastError): self
    {
        return new self(
            $lastUpdate,
            self::calcLastAttempt($lastUpdate, $lastError),
            self::calcIsErroring($lastUpdate, $lastError),
        );
    }

    /*
     * Whether the feed's most recent fetch attempt ended in an error.
     * Guards against the legacy sentinel value of lastError(), which is not comparable to lastUpdate().
     */
    private static function calcIsErroring(int $lastUpdate, int $lastError): bool
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
}
