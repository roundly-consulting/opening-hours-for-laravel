<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\DataTransferObjects;

/**
 * What a prune run removed (or would remove, on a dry run).
 */
final readonly class PruneResult
{
    public function __construct(
        public int $exceptionsPruned = 0,
        public int $rowsPurged = 0,
    ) {}
}
