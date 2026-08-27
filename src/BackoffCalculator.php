<?php

final class BackoffCalculator
{
    // Minimum size of the random skip range, in sweeps, even on a feed's very
    // first error. Without this floor, a fresh error's range would start at 1
    // sweep with no randomness at all - exactly the case that matters most for
    // a shared rate-limit event hitting many feeds in the same sweep.
    public const MIN_SKIP_SWEEPS = 4;

    /*
     * Random number of sweeps (>= 1) an erroring feed skips beyond baseTTL's own
     * sweep. Only ever called for a feed that is part of a shared-host error
     * group (groupSize > 1, e.g. many YouTube channel feeds erroring together on
     * a rate limit) - see calcBackoffTTL(), which gates backoff on group
     * membership before reaching here.
     *
     * The random range grows with how long the feed has been failing (errorAge in
     * whole sweeps), clamped to maxSweeps, so a group that keeps failing gets both
     * a wider possible spread and a longer expected wait over time, without needing
     * to persist an attempt counter. MIN_SKIP_SWEEPS floors the range even on a
     * fresh error, and maxSweeps caps it even when MIN_SKIP_SWEEPS would otherwise
     * exceed it (a coarse cron relative to maxTTL) - either way, skip never exceeds
     * maxSweeps, preserving the same eventual-retry guarantee maxTTL provides today.
     *
     * The range is bumped to fit the whole group and each member's groupRank
     * claims a distinct slot within it, instead of leaving dispersal to hash luck
     * - see AutoTTLStats::getErrorHostGroups(). hostOffset gives each host group a
     * distinct per-host offset so different host groups don't all default to slot
     * 0 for their rank-0 member.
     */
    public static function calcSkipSweeps(FeedAttempt $attempt, int $cronInterval, int $maxSweeps, ?ErrorGroupInfo $group = null): int
    {
        $group ??= new ErrorGroupInfo();

        $errorAge = $attempt->lastAttempt - $attempt->lastUpdate;
        $range = min($maxSweeps, max(self::MIN_SKIP_SWEEPS, intdiv($errorAge, $cronInterval)));
        $range = max(1, $range);

        // Bump the range so a large simultaneous group actually gets enough
        // distinct slots to spread into, still capped at maxSweeps.
        $range = min($maxSweeps, max($range, $group->size));

        // Distinct per-host offset so different host groups don't all default
        // to slot 0 for their rank-0 member.
        $hostOffset = (int) (abs(crc32($group->host)) % $range);
        $slot = ($group->rank + $hostOffset) % $range;

        return 1 + $slot;
    }

    /*
     * TTL to apply to a feed that keeps erroring.
     *
     * When cronInterval > 0 (cron cadence learned), the feed randomly skips a
     * growing number of cron sweeps via calcSkipSweeps() - see there for why the
     * skip is randomized rather than a fixed doubling schedule. backoffTTL always
     * lands on a real predicted sweep by construction, so snapToNextSweep() has
     * nothing left to correct once the cron interval is known.
     *
     * When cronInterval == 0 (not learned yet), falls back to a bootstrap
     * seconds-based formula: since each retry only happens after waiting
     * backoffTTL, errorAge roughly doubles each pass once it exceeds baseTTL,
     * producing exponential backoff bounded by maxTTL without needing to persist
     * an attempt counter. No per-feed randomization is available yet at this
     * stage (see calcSkipSweeps) - this is a short bootstrap window before the
     * cron cadence is learned, not a steady-state throttling strategy.
     *
     * Never returns less than baseTTL, even if baseTTL itself exceeds maxTTL
     * (calcAdjustedTTL's defaultTTL > maxTTL escape hatch) - an errored feed
     * must never be checked more eagerly than a healthy one.
     *
     * groupSize <= 1 means no other AutoTTL-managed feed is currently erroring
     * on the same host (see AutoTTLStats::getErrorHostGroups()) - back-off only
     * exists to spread out a shared-host pile-up, so a lone erroring feed always
     * returns baseTTL unchanged, same as a non-erroring feed.
     */
    public static function calcBackoffTTL(int $baseTTL, FeedAttempt $attempt, int $maxTTL, int $cronInterval = 0, ?ErrorGroupInfo $group = null): int
    {
        $group ??= new ErrorGroupInfo();

        if (!$attempt->isErroring || $group->size <= 1) {
            return $baseTTL;
        }

        if ($cronInterval > 0) {
            // Budget sweeps from the headroom remaining after baseTTL, not from
            // maxTTL alone - otherwise a large baseTTL leaves no room and the
            // result below can overshoot maxTTL.
            $headroom = $maxTTL - $baseTTL;
            $maxSweeps = $headroom > 0 ? max(1, intdiv($headroom, $cronInterval) + 1) : 1;
            $skipSweeps = self::calcSkipSweeps($attempt, $cronInterval, $maxSweeps, $group);

            return max($baseTTL, min($maxTTL, $baseTTL + ($skipSweeps - 1) * $cronInterval));
        }

        $errorAge = $attempt->lastAttempt - $attempt->lastUpdate;

        return max($baseTTL, min($maxTTL, max($baseTTL, $errorAge)));
    }
}
