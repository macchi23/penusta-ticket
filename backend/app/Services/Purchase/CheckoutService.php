<?php

namespace App\Services\Purchase;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(
        private SaleGate $gate,
        private SaleMethodRegistry $methods,
        private SaleConditions $conditions,
        private DbHoldExpiry $holdExpiry,
        private MockPaymentGateway $gateway,
        private AllocationLocks $locks,
    ) {}

    /**
     * @throws PurchaseException
     * @throws PaymentDeclined 状態は巻き戻し済み
     */
    public function checkout(User $user, int $orderId): CheckoutResult
    {
        $outcome = DB::transaction(function () use ($user, $orderId) {
            $order = Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first() ?? throw new ModelNotFoundException;

            // Paid は何も消費し直さないので allocation をロックせず返す（待たされない）
            if ($order->status === OrderStatus::Paid) {
                return new CheckoutResult($order, false, 0, CarbonImmutable::now());
            }
            if ($order->status === OrderStatus::Expired) {
                return PurchaseException::cartExpired();
            }

            // ロック順は仮押さえ側と同じ: カート行 → allocation 行（block_id 昇順）→ tickets
            // 確定対象になりうる held のブロックだけロックする（released / expired のブロックは握らない）
            $blocks = Block::whereIn('id', Ticket::query()
                ->whereIn('id', OrderItem::where('order_id', $order->id)->select('ticket_id'))
                ->where('status', TicketStatus::Held->value)
                ->select('block_id'))
                ->orderBy('id')
                ->get();
            $allocations = $this->locks->forPurchase($order->match_id, $blocks);

            // allocation のロック待ちの間に期限が過ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();

            if ($order->expires_at !== null && $order->expires_at <= $now) {
                $this->holdExpiry->expireCart($order->id, $now);

                // 回収をコミットするため、例外はトランザクションの外で投げる
                return PurchaseException::cartExpired();
            }

            $tickets = Ticket::query()
                ->whereIn('id', OrderItem::where('order_id', $order->id)->select('ticket_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lapsed = $tickets->contains(fn (Ticket $t) => $t->status === TicketStatus::Expired
                || ($t->status === TicketStatus::Held && $t->expires_at <= $now));
            if ($lapsed) {
                // 部分確定はしない。回収をコミットするため、例外は外で投げる
                $this->holdExpiry->expireCart($order->id, $now);

                return PurchaseException::cartExpired();
            }

            $ticketIds = $tickets->filter(fn (Ticket $t) => $t->status === TicketStatus::Held)->pluck('id')->values();
            if ($ticketIds->isEmpty()) {
                throw PurchaseException::cartEmpty();
            }

            $match = $order->match;
            // 資格は checkout 時の規則で判定する（hold 時のフェーズは問わない）
            $phase = $this->gate->open($user, $match, $now);
            $this->methods->for($phase->method);
            $items = OrderItem::with(['ticket.block', 'ticketType'])
                ->where('order_id', $order->id)
                ->whereIn('ticket_id', $ticketIds)
                ->get();

            $total = 0;
            foreach ($items as $item) {
                $block = $item->ticket->block;
                $allocation = $allocations[$block->id] ?? 'closed';
                $this->conditions->assertSellable($match, $allocation);
                $this->conditions->assertTicketType($item->ticketType);

                $price = MatchPrice::where('match_id', $match->id)
                    ->where('seat_category_id', $block->seat_category_id)
                    ->where('ticket_type_id', $item->ticket_type_id)
                    ->value('price');
                if ($price === null) {
                    throw PurchaseException::priceNotFound();
                }
                $item->price = (int) $price;
                $item->save();
                $total += (int) $price;
            }

            // 外部 API に差し替えるときも、この呼び出しは order / tickets のロックを持ったまま行うこと
            $payment = $this->gateway->charge($order, $total);
            if (! $payment->ok) {
                throw new PaymentDeclined($payment->reason ?? 'declined', $ticketIds->count(), $match->id, $order->id, $now);
            }

            Ticket::whereIn('id', $ticketIds)->update([
                'status' => TicketStatus::Paid->value,
                'expires_at' => null,
                'updated_at' => $now,
            ]);
            $order->status = OrderStatus::Paid;
            $order->total = $total;
            $order->expires_at = null;
            $order->ordered_at = $now;
            $order->sale_phase_id = $phase->id;
            $order->save();

            return new CheckoutResult($order, true, $ticketIds->count(), $now);
        }, 3);

        if ($outcome instanceof PurchaseException) {
            throw $outcome;
        }

        return $outcome;
    }
}
