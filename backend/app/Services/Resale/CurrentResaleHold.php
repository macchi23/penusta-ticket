<?php

namespace App\Services\Resale;

use App\Models\ResaleHold;
use Carbon\CarbonImmutable;

final readonly class CurrentResaleHold
{
    public function __construct(
        public ?ResaleHold $hold,
        public CarbonImmutable $now,
    ) {}
}
