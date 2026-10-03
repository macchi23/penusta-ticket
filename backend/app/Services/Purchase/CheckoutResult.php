<?php

namespace App\Services\Purchase;

use App\Models\Order;
use Carbon\CarbonImmutable;

final readonly class CheckoutResult
{
    /**
     * @param  bool  $purchased  この呼び出しで確定した（冪等な再実行は false）
     */
    public function __construct(
        public Order $order,
        public bool $purchased,
        public int $quantity,
        public CarbonImmutable $now,
    ) {}
}
