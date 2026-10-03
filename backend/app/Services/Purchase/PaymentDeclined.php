<?php

namespace App\Services\Purchase;

use Carbon\CarbonImmutable;
use Exception;

/**
 * トランザクションを巻き戻して外へ決済失敗を伝える内部用の例外。
 */
class PaymentDeclined extends Exception
{
    public function __construct(public readonly string $reason, public readonly int $quantity, public readonly int $matchId, public readonly int $orderId, public readonly CarbonImmutable $now)
    {
        parent::__construct($reason);
    }
}
