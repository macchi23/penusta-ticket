<?php

namespace App\Services\Resale;

use App\Models\ResaleHold;
use Carbon\CarbonImmutable;

final readonly class ResaleHoldResult
{
    /**
     * @param  bool  $created  この呼び出しで作った（同じ出品の再 hold は false）
     */
    public function __construct(
        public ResaleHold $hold,
        public bool $created,
        public CarbonImmutable $now,
    ) {}
}
