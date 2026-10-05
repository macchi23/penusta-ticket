<?php

namespace App\Services\Purchase;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    public function __construct(
        private FirstComeSaleMethod $saleMethod,
        private DbUnreservedStock $unreservedStock,
        private DbHoldExpiry $holdExpiry,
        private AllocationLocks $locks,
    ) {}

    /**
     * 現在の pending カート。無い / 期限切れなら cart が null（期限切れは回収する）。
     */
    public function current(User $user, FootballMatch $match): CurrentCart
    {
        return DB::transaction(function () use ($user, $match) {
            $cart = $this->lockPending($user, $match);
            // ロック待ちの間に期限が過ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            if ($cart !== null && $this->isExpired($cart, $now)) {
                $this->holdExpiry->expireCart($cart->id, $now);
                $cart = null;
            }

            return new CurrentCart($cart, $now);
        }, 3);
    }

    public function addItem(User $user, FootballMatch $match, ?int $seatId, ?int $blockId, ?int $ticketTypeId): CartChange
    {
        return DB::transaction(function () use ($user, $match, $seatId, $blockId, $ticketTypeId) {
            $locked = $this->lockPending($user, $match);
            // ロック待ちの間に期限が過ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            $cart = $this->ensureCart($locked, $user, $match, $now);

            $type = $ticketTypeId !== null
                ? TicketType::findOrFail($ticketTypeId)
                : TicketType::where('code', 'adult')->first();
            if ($type === null) {
                throw PurchaseException::priceNotFound();
            }

            $seat = null;
            if ($seatId !== null) {
                $seat = Seat::with('block')->findOrFail($seatId);
                $block = $seat->block;

                $alreadyHeld = $this->liveTickets($cart, $now)->where('seat_id', $seat->id)->exists();
                if ($alreadyHeld) {
                    return new CartChange($cart, $now, false, $block, $seat->id);
                }
                if ($block->seating_type !== 'reserved') {
                    throw PurchaseException::notOnSale();
                }
            } else {
                $block = Block::findOrFail($blockId);
                if ($block->seating_type !== 'unreserved') {
                    throw PurchaseException::notOnSale();
                }
            }

            // チケットを作る前に allocation 行をロックし、ロックした読み取りの値で判定する
            $allocation = $this->locks->forPurchase($match->id, [$block])[$block->id];
            // $now は取り直さない（checkout はロック後に取る）。カート期限の判定は orders ロック直後の $now で
            // 済んでおり、ここで進めると期限切れ held を残したまま expires_at だけ延長しうる。

            $heldCount = $this->liveTickets($cart, $now)->where('status', TicketStatus::Held->value)->count();
            $this->saleMethod->assertCanHold($user, $match, $block, $allocation, $heldCount, $type);
            $price = $this->priceFor($match, $block, $type);

            if ($heldCount === 0) {
                $cart->expires_at = $now->addMinutes((int) config('ticketing.hold_minutes'));
                $cart->save();
            }
            $expiresAt = $cart->expires_at;

            $ticket = $seat !== null
                ? $this->holdSeat($match, $block, $seat, $expiresAt, $now)
                : $this->unreservedStock->hold($match, $block, $cart, $expiresAt, $now);

            OrderItem::create([
                'order_id' => $cart->id,
                'ticket_id' => $ticket->id,
                'ticket_type_id' => $type->id,
                'price' => $price,
            ]);
            $this->refreshTotal($cart, $now);

            return new CartChange($cart, $now, true, $block, $seat?->id);
        }, 3);
    }

    /**
     * 自由席ブロックの券種ごとの枚数を、このブロックの live held がその枚数になるよう反映する。
     *
     * @param  array<int, array{ticket_type_id: int, quantity: int}>  $quantities
     */
    public function setUnreservedQuantities(User $user, FootballMatch $match, Block $block, array $quantities): CartChange
    {
        return DB::transaction(function () use ($user, $match, $block, $quantities) {
            if ($block->seating_type !== 'unreserved') {
                throw PurchaseException::notOnSale();
            }
            $allocation = MatchBlockAllocation::where('match_id', $match->id)
                ->where('block_id', $block->id)
                ->value('allocation') ?? 'closed';
            $this->saleMethod->assertSellable($match, $allocation);

            $wanted = [];
            foreach ($quantities as $row) {
                $type = TicketType::findOrFail($row['ticket_type_id']);
                $this->saleMethod->assertTicketType($type);
                $wanted[] = [$type, (int) $row['quantity'], $this->priceFor($match, $block, $type)];
            }

            $locked = $this->lockPending($user, $match);
            // ロック待ちの間に期限が過ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            $cart = $this->ensureCart($locked, $user, $match, $now);

            // 書き込みより前に差分だけを計算する（ロック順: orders → allocation → tickets を守るため）
            $plan = function () use ($cart, $block, $wanted, $now) {
                $blockItems = OrderItem::with('ticket')
                    ->where('order_id', $cart->id)
                    ->whereIn('ticket_id', $this->liveTickets($cart, $now)
                        ->where('status', TicketStatus::Held->value)
                        ->where('block_id', $block->id)
                        ->select('id'))
                    ->orderByDesc('id')
                    ->get();

                $heldBefore = $this->liveTickets($cart, $now)->where('status', TicketStatus::Held->value)->count();

                $drops = collect();
                $adds = [];
                $addTotal = 0;
                foreach ($wanted as [$type, $quantity, $price]) {
                    $mine = $blockItems->where('ticket_type_id', $type->id)->values();
                    $delta = $quantity - $mine->count();
                    if ($delta < 0) {
                        $drops = $drops->merge($mine->take(-$delta));
                    } elseif ($delta > 0) {
                        $adds[] = [$type, $price, $delta];
                        $addTotal += $delta;
                    }
                }

                return [$heldBefore, $drops, $adds, $addTotal];
            };

            [$heldBefore, $drops, $adds, $addTotal] = $plan();
            if ($addTotal > 0) {
                $this->locks->forPurchase($match->id, [$block]);
                // $now は取り直さない（addItem と同じ理由）
            }

            $released = $drops->count();
            if ($released > 0) {
                Ticket::whereIn('id', $drops->pluck('ticket_id'))
                    ->update(['status' => TicketStatus::Released->value, 'updated_at' => $now]);
            }

            $heldCount = $heldBefore - $released;
            $max = (int) config('ticketing.max_tickets_per_order');
            if ($heldCount + $addTotal > $max) {
                throw PurchaseException::cartLimitReached($max);
            }

            if ($addTotal > 0) {
                if ($heldBefore === 0) {
                    $cart->expires_at = $now->addMinutes((int) config('ticketing.hold_minutes'));
                    $cart->save();
                }
                $tickets = $this->unreservedStock->holdMany($match, $block, $cart, $cart->expires_at, $now, $addTotal);
                foreach ($adds as [$type, $price, $count]) {
                    foreach ($tickets->splice(0, $count) as $ticket) {
                        OrderItem::create([
                            'order_id' => $cart->id,
                            'ticket_id' => $ticket->id,
                            'ticket_type_id' => $type->id,
                            'price' => $price,
                        ]);
                    }
                }
            }
            $this->refreshTotal($cart, $now);

            return new CartChange($cart, $now, $addTotal + $released > 0, $block, null, $addTotal, $released);
        }, 3);
    }

    public function changeTicketType(User $user, FootballMatch $match, int $itemId, int $ticketTypeId): CartChange
    {
        $change = DB::transaction(function () use ($user, $match, $itemId, $ticketTypeId) {
            $cart = $this->lockPending($user, $match);
            // ロック待ちの間に期限が過ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            $locked = $this->findCartItem($cart, $itemId, $now);
            if ($locked === null) {
                return null;
            }
            [$cart, $item] = $locked;
            $ticket = $item->ticket;

            if (! $this->isLiveHeld($ticket, $now)) {
                throw PurchaseException::cartExpired();
            }

            $type = TicketType::findOrFail($ticketTypeId);
            $block = $ticket->block;
            $allocation = MatchBlockAllocation::where('match_id', $match->id)
                ->where('block_id', $block->id)
                ->value('allocation') ?? 'closed';
            $this->saleMethod->assertSellable($match, $allocation);
            $this->saleMethod->assertTicketType($type);

            $item->ticket_type_id = $type->id;
            $item->price = $this->priceFor($match, $block, $type);
            $item->save();
            $this->refreshTotal($cart, $now);

            return new CartChange($cart, $now, true, $block, $ticket->seat_id);
        }, 3);

        return $change ?? throw new ModelNotFoundException;
    }

    public function removeItem(User $user, FootballMatch $match, int $itemId): CartChange
    {
        $change = DB::transaction(function () use ($user, $match, $itemId) {
            $cart = $this->lockPending($user, $match);
            // ロック待ちの間に期限が過ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            $locked = $this->findCartItem($cart, $itemId, $now);
            if ($locked === null) {
                return null;
            }
            [$cart, $item] = $locked;
            $ticket = $item->ticket;

            $changed = $ticket->status === TicketStatus::Held;
            if ($changed) {
                $ticket->status = TicketStatus::Released;
                $ticket->save();
            }
            $this->refreshTotal($cart, $now);

            return new CartChange($cart, $now, $changed, $ticket->block, $ticket->seat_id);
        }, 3);

        return $change ?? throw new ModelNotFoundException;
    }

    private function holdSeat(FootballMatch $match, Block $block, Seat $seat, CarbonImmutable $expiresAt, CarbonImmutable $now): Ticket
    {
        $this->holdExpiry->releaseStaleSeat($match->id, $seat->id, $now);

        $rows = DB::table('tickets')->insertOrIgnoreReturning([
            'match_id' => $match->id,
            'block_id' => $block->id,
            'seat_id' => $seat->id,
            'status' => TicketStatus::Held->value,
            'qr_uuid' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['id']);

        if ($rows->isEmpty()) {
            throw PurchaseException::seatUnavailable();
        }

        return Ticket::findOrFail($rows->first()->id);
    }

    private function priceFor(FootballMatch $match, Block $block, TicketType $type): int
    {
        $price = MatchPrice::where('match_id', $match->id)
            ->where('seat_category_id', $block->seat_category_id)
            ->where('ticket_type_id', $type->id)
            ->value('price');

        if ($price === null) {
            throw PurchaseException::priceNotFound();
        }

        return (int) $price;
    }

    private function lockPending(User $user, FootballMatch $match): ?Order
    {
        return Order::where('user_id', $user->id)
            ->where('match_id', $match->id)
            ->where('status', OrderStatus::Pending->value)
            ->lockForUpdate()
            ->first();
    }

    private function ensureCart(?Order $cart, User $user, FootballMatch $match, CarbonImmutable $now): Order
    {
        if ($cart !== null && $this->isExpired($cart, $now)) {
            $this->holdExpiry->expireCart($cart->id, $now);
            $cart = null;
        }

        if ($cart === null) {
            DB::table('orders')->insertOrIgnore([
                'user_id' => $user->id,
                'match_id' => $match->id,
                'status' => OrderStatus::Pending->value,
                'channel' => 'web',
                'total' => 0,
                'expires_at' => $now->addMinutes((int) config('ticketing.hold_minutes')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $cart = $this->lockPending($user, $match);
        }

        return $cart;
    }

    /**
     * 見つからない場合と、期限切れで回収した場合は null（呼び出し側で commit した後に 404 にする）。
     *
     * @return array{0: Order, 1: OrderItem}|null
     */
    private function findCartItem(?Order $cart, int $itemId, CarbonImmutable $now): ?array
    {
        if ($cart === null) {
            return null;
        }
        if ($this->isExpired($cart, $now)) {
            $this->holdExpiry->expireCart($cart->id, $now);

            return null;
        }

        $item = OrderItem::with('ticket.block')->where('order_id', $cart->id)->find($itemId);
        if ($item === null) {
            return null;
        }

        return [$cart, $item];
    }

    private function isExpired(Order $cart, CarbonImmutable $now): bool
    {
        return $cart->expires_at !== null && $cart->expires_at <= $now;
    }

    private function isLiveHeld(Ticket $ticket, CarbonImmutable $now): bool
    {
        return $ticket->status === TicketStatus::Held
            && $ticket->expires_at !== null
            && $ticket->expires_at > $now;
    }

    /**
     * カート内の、paid または期限内 held の ticket。
     */
    private function liveTickets(Order $cart, CarbonImmutable $now)
    {
        return Ticket::query()
            ->consumingInventory($now)
            ->whereIn('id', OrderItem::where('order_id', $cart->id)->select('ticket_id'));
    }

    private function refreshTotal(Order $cart, CarbonImmutable $now): void
    {
        $cart->total = (int) OrderItem::where('order_id', $cart->id)
            ->whereIn('ticket_id', $this->liveTickets($cart, $now)->select('id'))
            ->sum('price');
        $cart->save();
    }
}
