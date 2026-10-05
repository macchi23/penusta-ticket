<?php

namespace App\Services\Purchase;

use App\Enums\Allocation;
use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\TicketType;
use App\Models\User;

/**
 * 先着販売の販売条件。販売方式を差し替えるときはここだけを変える。
 */
class FirstComeSaleMethod
{
    public function assertCanHold(User $user, FootballMatch $match, Block $block, string $allocation, int $heldCount, TicketType $type): void
    {
        $this->assertSellable($match, $allocation);

        $max = (int) config('ticketing.max_tickets_per_order');
        if ($heldCount >= $max) {
            throw PurchaseException::cartLimitReached($max);
        }

        $this->assertTicketType($type);
    }

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
