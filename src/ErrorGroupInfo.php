<?php

final class ErrorGroupInfo
{
    public function __construct(
        public readonly int $rank = 0,
        public readonly int $size = 1,
        public readonly string $host = '',
    ) {
    }
}
