<?php

namespace App\Services\Resale;

use App\Models\Order;
use App\Models\ResaleHold;
use Carbon\CarbonImmutable;

final readonly class ResaleCheckoutResult
{
    /**
     * @param  bool  $created  この呼び出しで確定した（冪等な再実行は false）
     */
    public function __construct(
        public Order $order,
        public ResaleHold $hold,
        public bool $created,
        public CarbonImmutable $now,
    ) {}
}
