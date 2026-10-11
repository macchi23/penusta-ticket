<?php

namespace App\Services\Purchase;

use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\User;

/**
 * 先着販売の販売条件。販売方式を差し替えるときはここだけを変える。
 */
class FirstComeSaleMethod implements SaleMethod
{
    public function assertCanHold(User $user, FootballMatch $match, Block $block, int $heldCount): void
    {
        $max = (int) config('ticketing.max_tickets_per_order');
        if ($heldCount >= $max) {
            throw PurchaseException::cartLimitReached($max);
        }
    }
}
