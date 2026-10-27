<?php

final class RetryAfter
{
    /*
     * Timestamp until which FreshRSS's own HTTP Retry-After pause blocks fetching
     * this feed, or 0 if there is none.
     *
     * FreshRSS records the pause itself on a 429 or 503 response (honoring the
     * Retry-After header, else limits.retry_after_default) - domain-wide for public
     * hosts, per URL for hosts on a local network - and FreshRSS_Feed::load()
     * refuses to fetch until it expires. AutoTTL only mirrors it so a paused feed
     * is skipped quietly instead of being stamped as errored on every sweep.
     */
    public static function forFeed(FreshRSS_Feed $feed): int
    {
        // Mirrors FreshRSS_Feed::load(): the pause is stored for the URL as
        // requested, not as stored for the feed.
        $url = htmlspecialchars_decode($feed->url(), ENT_QUOTES);
        if (str_ends_with($url, '#force_feed')) {
            $url = substr($url, 0, -strlen('#force_feed'));
        }

        return FreshRSS_http_Util::getRetryAfter($url, $feed->proxyParam());
    }
}
