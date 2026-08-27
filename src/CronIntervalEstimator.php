<?php

class CronIntervalEstimator
{
    // Real cron/systemd cadences are never sub-minute in practice, so any
    // gap shorter than this is assumed to be two feeds in the same
    // actualize sweep, not two separate sweeps.
    public const MIN_SWEEP_GAP = 60;

    public static function updateEstimate(int $now, int $lastHookTs, int $estimate): CronEstimate
    {
        if ($lastHookTs === 0) {
            // First observation ever for this user - nothing to compare against yet.
            return new CronEstimate($estimate, $now);
        }

        $gap = $now - $lastHookTs;
        if ($gap < self::MIN_SWEEP_GAP) {
            // Still within the same actualize sweep; not a new sample.
            return new CronEstimate($estimate, $lastHookTs);
        }

        // New sweep detected. Ratchet up immediately on a bigger gap (safe
        // to trust right away - a gap that big really did happen); ease
        // down gradually on a smaller one, so a single fast fluke doesn't
        // instantly undercut backoff for erroring feeds.
        $newEstimate = $gap > $estimate
            ? $gap
            : (int) (0.7 * $estimate + 0.3 * $gap);

        return new CronEstimate($newEstimate, $now);
    }
}
