<?php

namespace App\Services\Resale;

use App\Models\ResaleHold;
use Carbon\CarbonImmutable;

final readonly class ResaleReleaseResult
{
    /**
     * @param  bool  $released  この呼び出しで有効な仮押さえを解除した
     */
    public function __construct(
        public ResaleHold $hold,
        public bool $released,
        public CarbonImmutable $now,
    ) {}
}
