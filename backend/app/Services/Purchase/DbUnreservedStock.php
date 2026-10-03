<?php

namespace App\Services\Purchase;

use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\Order;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class DbUnreservedStock
{
    public function __construct(private FirstComeSaleMethod $saleMethod) {}

    /**
     * 自由席を 1 枚仮押さえする。呼び出し側でカート行をロック済みであること
     * （ロック順: カート行 → allocation 行 → tickets）。
     */
    public function hold(FootballMatch $match, Block $block, Order $cart, CarbonImmutable $expiresAt, CarbonImmutable $now): Ticket
    {
        if ($block->seating_type !== 'unreserved') {
            throw PurchaseException::notOnSale();
        }

        $allocation = MatchBlockAllocation::where('match_id', $match->id)
            ->where('block_id', $block->id)
            ->lockForUpdate()
            ->first();

        if ($allocation === null) {
            throw PurchaseException::notOnSale();
        }
        $this->saleMethod->assertAllocationSellable($allocation->allocation);

        $consumed = Ticket::where('match_id', $match->id)
            ->where('block_id', $block->id)
            ->consumingInventory($now)
            ->count();

        if ($consumed >= $block->capacity) {
            throw PurchaseException::soldOut();
        }

        return Ticket::create([
            'match_id' => $match->id,
            'block_id' => $block->id,
            'seat_id' => null,
            'status' => 'held',
            'qr_uuid' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
        ]);
    }
}
