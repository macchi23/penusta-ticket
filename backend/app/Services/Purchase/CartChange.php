<?php

namespace App\Services\Purchase;

use App\Models\Block;
use App\Models\Order;
use Carbon\CarbonImmutable;

final class CartChange
{
    public function __construct(
        public readonly Order $cart,
        public readonly CarbonImmutable $now,
        public readonly bool $changed,
        public readonly Block $block,
        public readonly ?int $seatId,
        public readonly int $added = 0,
        public readonly int $released = 0,
    ) {}
}
