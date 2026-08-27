<?php

final class AutoTTLConfig
{
    public function __construct(
        public readonly int $defaultTTL,
        public readonly int $maxTTL,
        public readonly int $statsCount,
        public readonly int $minTTL = 0,
        public readonly int $cronLastHookTs = 0,
        public readonly int $cronIntervalEstimate = 0,
    ) {
    }
}
