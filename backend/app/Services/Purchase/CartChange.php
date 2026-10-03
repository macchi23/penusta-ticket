<?php

namespace App\Services\Purchase;

use App\Models\Block;
use App\Models\Order;

final class CartChange
{
    public function __construct(
        public readonly Order $cart,
        public readonly bool $changed,
        public readonly Block $block,
        public readonly ?int $seatId,
    ) {}
}
