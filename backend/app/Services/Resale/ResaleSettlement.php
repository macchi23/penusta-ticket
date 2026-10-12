<?php

namespace App\Services\Resale;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ResaleException;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * リセール購入の共通部品（即時購入 ResaleService と、仮押さえ経由 ResaleCartService が共有する）。
 * ロックの取得順は呼び出し側が決める: advisory → tickets → resale_listings → resale_holds。
 */
class ResaleSettlement
{
    /** pg_advisory_xact_lock の第 1 引数。リセール購入上限（buyer × match）の直列化用 */
    private const LIMIT_LOCK_NAMESPACE = 7301;

    public function __construct(
        private ResaleWindow $window,
        private MockPaymentGateway $gateway,
    ) {}

    /** ロック待ちのタイムアウト（55P03）= 他の人が同じ出品を処理中、として listing_unavailable に変換する。 */
    public function translatingLockTimeout(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            if ($e->getCode() === '55P03') {
                throw ResaleException::listingUnavailable();
            }
            throw $e;
        }
    }

    /** トランザクションの先頭で呼ぶ。行ロックにも advisory lock にも効く（トランザクション終了で元に戻る）。 */
    public function setLockTimeout(): void
    {
        DB::statement(sprintf("SET LOCAL lock_timeout = '%dms'", (int) config('ticketing.resale.lock_timeout_ms')));
    }

    /** 同じ buyer の同時操作で上限を超えないよう、(buyer, match) 単位で直列化する。 */
    public function lockBuyer(User $buyer, int $matchId): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [
            self::LIMIT_LOCK_NAMESPACE,
            crc32("{$buyer->id}:{$matchId}") & 0x7FFFFFFF,
        ]);
    }

    /** @throws ResaleException */
    public function assertWithinLimit(User $buyer, int $matchId): void
    {
        $max = (int) config('ticketing.max_tickets_per_order');
        $owned = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $buyer->id)
            ->where('orders.match_id', $matchId)
            ->where('orders.kind', OrderKind::Resale->value)
            ->where('orders.status', OrderStatus::Paid->value)
            ->count();
        if ($owned >= $max) {
            throw ResaleException::limitReached($max);
        }
    }

    /**
     * 出品が今買える状態か。チケットと出品行のロックを取った後、その後に取った $now で呼ぶ。
     *
     * @throws ResaleException
     */
    public function assertPurchasable(User $buyer, Ticket $ticket, ResaleListing $listing, CarbonImmutable $now): void
    {
        if ($listing->status !== ResaleListingStatus::Active || $ticket->status !== TicketStatus::Paid) {
            throw ResaleException::listingUnavailable();
        }
        if ($now >= $listing->closes_at) {
            throw ResaleException::closed();
        }
        $this->window->assertOpen(FootballMatch::findOrFail($listing->match_id), $now);
        if ($listing->seller_user_id === $buyer->id) {
            throw ResaleException::ownListing();
        }
    }

    /**
     * 元チケットを resold、新チケットと resale の注文を作り、決済して出品を sold にする。
     * 呼び出し側のトランザクション内で、チケットと出品行のロックを持ったまま呼ぶこと（決済失敗は例外で巻き戻す）。
     *
     * @throws ResaleException
     */
    public function settle(User $buyer, Ticket $ticket, ResaleListing $listing, CarbonImmutable $now): Order
    {
        // 先に resold にして席の占有を外さないと、新チケットが部分ユニークに当たる
        $ticket->status = TicketStatus::Resold;
        $ticket->save();
        $newTicket = Ticket::create([
            'match_id' => $ticket->match_id,
            'block_id' => $ticket->block_id,
            'seat_id' => $ticket->seat_id,
            'season_pass_seat_id' => null,
            'status' => TicketStatus::Paid,
            'qr_uuid' => (string) Str::uuid(),
        ]);

        $total = $listing->price + $listing->buyer_fee;
        $order = Order::create([
            'user_id' => $buyer->id,
            'match_id' => $listing->match_id,
            'status' => OrderStatus::Paid,
            'kind' => OrderKind::Resale,
            'sale_phase_id' => null,
            'total' => $total,
            'channel' => 'web',
            'ordered_at' => $now,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'ticket_id' => $newTicket->id,
            'ticket_type_id' => $listing->ticket_type_id,
            'price' => $listing->price,
        ]);

        // 外部 API に差し替えるときも、この呼び出しはチケットと出品行のロックを持ったまま行うこと
        if (! $this->gateway->charge($order, $total)->ok) {
            throw ResaleException::paymentFailed();
        }

        $listing->status = ResaleListingStatus::Sold;
        $listing->buyer_order_id = $order->id;
        $listing->new_ticket_id = $newTicket->id;
        $listing->save();

        return $order;
    }
}
