<?php

namespace App\Services\Admin;

use App\Enums\Allocation;
use Carbon\CarbonImmutable;

class AllocationChangeResult
{
    /**
     * @param  array<int, array{block_id: int, from: Allocation, to: Allocation}>  $changed
     */
    public function __construct(
        public readonly string $changeSetId,
        public readonly CarbonImmutable $changedAt,
        public readonly array $changed,
    ) {}
}
