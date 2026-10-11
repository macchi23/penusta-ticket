<?php

namespace App\Services\Purchase;

use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DbUnreservedStock
{
    public function __construct(private SaleConditions $conditions, private AllocationLocks $locks) {}

    /**
     * 自由席を 1 枚仮押さえする。呼び出し側でカート行をロック済みであること
     * （ロック順: カート行 → allocation 行 → tickets）。
     */
    public function hold(FootballMatch $match, Block $block, Order $cart, CarbonImmutable $expiresAt, CarbonImmutable $now): Ticket
    {
        return $this->holdMany($match, $block, $cart, $expiresAt, $now, 1)->first();
    }

    /**
     * 自由席を count 枚まとめて仮押さえする。残席が足りなければ 1 枚も作らず sold_out。
     *
     * @return Collection<int, Ticket>
     */
    public function holdMany(FootballMatch $match, Block $block, Order $cart, CarbonImmutable $expiresAt, CarbonImmutable $now, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }
        if ($block->seating_type !== 'unreserved') {
            throw PurchaseException::notOnSale();
        }

        // addItem などが同じ行を先にロックしていても、同じモード（FOR UPDATE）なので問題ない
        $allocation = $this->locks->forPurchase($match->id, [$block])[$block->id];
        $this->conditions->assertAllocationSellable($allocation);

        $consumed = Ticket::where('match_id', $match->id)
            ->where('block_id', $block->id)
            ->consumingInventory($now)
            ->count();

        if ($consumed + $count > $block->capacity) {
            throw PurchaseException::soldOut();
        }

        return collect(range(1, $count))->map(fn () => Ticket::create([
            'match_id' => $match->id,
            'block_id' => $block->id,
            'seat_id' => null,
            'status' => 'held',
            'qr_uuid' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
        ]));
    }
}
