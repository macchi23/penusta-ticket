<?php

namespace App\Services\Purchase;

use App\Enums\Allocation;
use App\Exceptions\PurchaseException;
use App\Models\FootballMatch;
use App\Models\TicketType;

/**
 * 販売方式に依存しない共通の販売条件（試合の状態・ブロックの割当・券種）。
 */
class SaleConditions
{
    public function assertSellable(FootballMatch $match, string $allocation): void
    {
        if ($match->status !== 'on_sale') {
            throw PurchaseException::notOnSale('この試合は現在販売していません。');
        }

        $this->assertAllocationSellable($allocation);
    }

    public function assertAllocationSellable(string $allocation): void
    {
        if (! Allocation::tryFrom($allocation)?->isSellable()) {
            throw PurchaseException::notOnSale();
        }
    }

    public function assertTicketType(TicketType $type): void
    {
        if ($type->code === 'season_pass') {
            throw PurchaseException::notOnSale('この券種はオンラインでは購入できません。');
        }
    }
}
