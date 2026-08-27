<?php

final class CronEstimate
{
    public function __construct(
        public readonly int $estimate,
        public readonly int $lastHookTs,
    ) {
    }
}
