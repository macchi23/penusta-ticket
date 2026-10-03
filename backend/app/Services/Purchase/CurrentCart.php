<?php

namespace App\Services\Purchase;

use App\Models\Order;
use Carbon\CarbonImmutable;

final readonly class CurrentCart
{
    public function __construct(
        public ?Order $cart,
        public CarbonImmutable $now,
    ) {}
}
