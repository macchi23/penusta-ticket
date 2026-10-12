<?php

namespace App\Services\Resale;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ResaleException;
use App\Models\FootballMatch;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * チケット 1 枚ずつの出品と、仮押さえなしの即時購入。
 *
 * ロック順はすべての操作で「元チケット FOR UPDATE → 出品行 FOR UPDATE」に揃える（出品行は ticket_id が不変なので、
 * ロックなしで読んで ticket_id を得てから取る）。expireDue は出品行だけを更新し、チケットを掴まない。
 *
 * allocation 行はロックしない。購入は paid を 1 枚 resold にして 1 枚 paid を作るだけで consuming 件数が変わらず、
 * チケットのあるブロックは割当変更できない（AllocationChangeService の has_tickets）ので、管理者の変更とも競合しない。
 */
class ResaleService
{
    /** pg_advisory_xact_lock の第 1 引数。リセール購入上限（buyer × match）の直列化用 */
    private const LIMIT_LOCK_NAMESPACE = 7301;

    public function __construct(
        private ResaleWindow $window,
        private MockPaymentGateway $gateway,
    ) {}

    /**
     * @throws ResaleException
     */
    public function list(User $seller, Ticket $ticket, int $price): ResaleListing
    {
        return DB::transaction(function () use ($seller, $ticket, $price) {
            $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->first() ?? throw ResaleException::notOwner();

            $order = $this->ownerOrder($locked);

            // ロック待ちの間に締切を跨ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            $match = FootballMatch::findOrFail($locked->match_id);
            $hasActive = ResaleListing::where('ticket_id', $locked->id)->where('status', ResaleListingStatus::Active->value)->exists();
            if ($blocker = $this->listBlocker($seller, $locked, $order, $hasActive, $this->window->isOpen($match, $now))) {
                throw $blocker;
            }

            [$facePrice, $ticketTypeId] = $this->facePrice($locked, $order, $match);
            $min = (int) config('ticketing.resale.min_price');
            if ($facePrice < $min) {
                throw ResaleException::notListable('このチケットは定価が低いため出品できません。');
            }
            if ($price < $min || $price > $facePrice) {
                throw ResaleException::priceOutOfRange($min, $facePrice);
            }

            $sellerFee = (int) round(round($price * (float) config('ticketing.resale.seller_fee_rate'), 6));
            $payout = $price - $sellerFee - (int) config('ticketing.resale.transfer_fee');
            if ($payout < 0) {
                throw ResaleException::priceOutOfRange($min, $facePrice);
            }

            try {
                // 部分ユニークに当たったとき、外側のトランザクションを中断させないようセーブポイントで囲む
                return DB::transaction(fn () => ResaleListing::create([
                    'ticket_id' => $locked->id,
                    'seller_user_id' => $seller->id,
                    'match_id' => $locked->match_id,
                    'block_id' => $locked->block_id,
                    'ticket_type_id' => $ticketTypeId,
                    'face_price' => $facePrice,
                    'price' => $price,
                    'seller_fee' => $sellerFee,
                    'buyer_fee' => (int) config('ticketing.resale.buyer_fee'),
                    'payout' => $payout,
                    'status' => ResaleListingStatus::Active,
                    'closes_at' => $this->window->closesAt($match),
                ]));
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    throw ResaleException::notListable('このチケットはすでに出品中です。');
                }
                throw $e;
            }
        }, 3);
    }

    /**
     * 出品できない理由（できるなら null）。DB を読まない純関数で、list() の検証と API の can_list が同じものを使う。
     * 定価の下限は facePrice 側で見る（listingFacePrice）。
     */
    public function listBlocker(User $user, Ticket $ticket, ?Order $ownerOrder, bool $hasActiveListing, bool $windowOpen): ?ResaleException
    {
        if ($ownerOrder === null || $ownerOrder->user_id !== $user->id) {
            return ResaleException::notOwner();
        }
        if ($ticket->status !== TicketStatus::Paid || $ownerOrder->kind === OrderKind::Resale) {
            return ResaleException::notListable();
        }
        if (! $windowOpen) {
            return ResaleException::closed();
        }
        if ($hasActiveListing) {
            return ResaleException::notListable('このチケットはすでに出品中です。');
        }

        return null;
    }

    /** 取り下げられるか。active なら期間外(締切後で失効前、on_sale 以外)でも可。cancel() の検証と API の can_cancel が共有する。 */
    public function canCancel(ResaleListingStatus $status): bool
    {
        return $status === ResaleListingStatus::Active;
    }

    /**
     * 出品の定価と買い手の券種 id。年パス券は単券の大人価格から割り引く。
     * 算出できない（価格や大人券種が無い）ときは null。
     *
     * @return array{0: int, 1: int}|null [定価, 買い手の券種 id]
     */
    public function listingFacePrice(OrderKind $kind, int $itemPrice, int $itemTypeId, ?int $singleAdultPrice, ?int $adultTypeId): ?array
    {
        if ($kind === OrderKind::Purchase) {
            return [$itemPrice, $itemTypeId];
        }
        if ($singleAdultPrice === null || $adultTypeId === null) {
            return null;
        }
        $rate = (float) config('ticketing.resale.season_pass_discount_rate');

        return [(int) floor(round($singleAdultPrice * (1 - $rate), 6)), $adultTypeId];
    }

    /**
     * @throws ResaleException
     */
    public function cancel(User $seller, ResaleListing $listing): ResaleListing
    {
        return DB::transaction(function () use ($seller, $listing) {
            $ticketId = ResaleListing::whereKey($listing->id)->value('ticket_id')
                ?? throw ResaleException::listingUnavailable();

            Ticket::whereKey($ticketId)->lockForUpdate()->first();
            $locked = ResaleListing::whereKey($listing->id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();

            if ($locked->seller_user_id !== $seller->id) {
                throw ResaleException::notOwner();
            }
            if (! $this->canCancel($locked->status)) {
                throw ResaleException::listingUnavailable();
            }

            $locked->status = ResaleListingStatus::Cancelled;
            $locked->save();

            return $locked;
        }, 3);
    }

    /**
     * 出品を即時確定で買う。元チケットは resold、買い手に新しいチケットと resale の注文ができる。
     *
     * @throws ResaleException
     */
    public function purchase(User $buyer, ResaleListing $listing): ResaleListing
    {
        try {
            return $this->purchaseInTransaction($buyer, $listing);
        } catch (QueryException $e) {
            // 行ロック・advisory lock の待ちがタイムアウトした = 他の人が同じ出品を処理中
            if ($e->getCode() === '55P03') {
                throw ResaleException::listingUnavailable();
            }
            throw $e;
        }
    }

    private function purchaseInTransaction(User $buyer, ResaleListing $listing): ResaleListing
    {
        return DB::transaction(function () use ($buyer, $listing) {
            // ロック待ちを長引かせない。行ロックにも pg_advisory_xact_lock にも効く（トランザクション終了で元に戻る）
            DB::statement(sprintf("SET LOCAL lock_timeout = '%dms'", (int) config('ticketing.resale.lock_timeout_ms')));

            $head = ResaleListing::whereKey($listing->id)->first(['id', 'ticket_id', 'match_id'])
                ?? throw ResaleException::listingUnavailable();

            // 同じ buyer の同時購入で上限を超えないよう、(buyer, match) 単位で直列化する
            DB::select('select pg_advisory_xact_lock(?, ?)', [
                self::LIMIT_LOCK_NAMESPACE,
                crc32("{$buyer->id}:{$head->match_id}") & 0x7FFFFFFF,
            ]);
            $max = (int) config('ticketing.max_tickets_per_order');
            $owned = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.user_id', $buyer->id)
                ->where('orders.match_id', $head->match_id)
                ->where('orders.kind', OrderKind::Resale->value)
                ->where('orders.status', OrderStatus::Paid->value)
                ->count();
            if ($owned >= $max) {
                throw ResaleException::limitReached($max);
            }

            $ticket = Ticket::whereKey($head->ticket_id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();
            $locked = ResaleListing::whereKey($head->id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();

            // ロック待ちの間に締切を跨ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();

            if ($locked->status !== ResaleListingStatus::Active || $ticket->status !== TicketStatus::Paid) {
                throw ResaleException::listingUnavailable();
            }
            if ($now >= $locked->closes_at) {
                throw ResaleException::closed();
            }
            $this->window->assertOpen(FootballMatch::findOrFail($locked->match_id), $now);
            if ($locked->seller_user_id === $buyer->id) {
                throw ResaleException::ownListing();
            }

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

            $total = $locked->price + $locked->buyer_fee;
            $order = Order::create([
                'user_id' => $buyer->id,
                'match_id' => $locked->match_id,
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
                'ticket_type_id' => $locked->ticket_type_id,
                'price' => $locked->price,
            ]);

            // 外部 API に差し替えるときも、この呼び出しはチケットと出品行のロックを持ったまま行うこと
            if (! $this->gateway->charge($order, $total)->ok) {
                throw ResaleException::paymentFailed();
            }

            $locked->status = ResaleListingStatus::Sold;
            $locked->buyer_order_id = $order->id;
            $locked->new_ticket_id = $newTicket->id;
            $locked->save();

            return $locked;
        }, 1); // 再試行しない: 決済をトランザクション内で呼ぶので、再試行は実決済で二重課金になりうる(ロック順は固定でデッドロックしない)
    }

    /**
     * 締切を過ぎた active の出品を失効させる。出品行だけを更新し、チケットは掴まない。
     *
     * @return int 失効させた件数
     */
    public function expireDue(CarbonImmutable $now): int
    {
        return ResaleListing::where('status', ResaleListingStatus::Active->value)
            ->where('closes_at', '<=', $now)
            ->update(['status' => ResaleListingStatus::Expired->value, 'updated_at' => $now]);
    }

    /** チケットの持ち主を決める注文（paid）。 */
    private function ownerOrder(Ticket $ticket): ?Order
    {
        return Order::whereIn('id', OrderItem::where('ticket_id', $ticket->id)->select('order_id'))
            ->where('status', OrderStatus::Paid->value)
            ->first();
    }

    /**
     * @return array{0: int, 1: int} [定価, 買い手の券種 id]
     *
     * @throws ResaleException
     */
    private function facePrice(Ticket $ticket, Order $order, FootballMatch $match): array
    {
        $item = OrderItem::where('order_id', $order->id)->where('ticket_id', $ticket->id)->firstOrFail();

        $adultId = null;
        $single = null;
        if ($order->kind !== OrderKind::Purchase) {
            $adultId = TicketType::where('code', 'adult')->value('id')
                ?? throw ResaleException::notListable('大人の券種が設定されていません。');
            $single = MatchPrice::where('match_id', $match->id)
                ->where('seat_category_id', $ticket->block->seat_category_id)
                ->where('ticket_type_id', $adultId)
                ->value('price') ?? throw ResaleException::notListable('価格が設定されていません。');
        }

        return $this->listingFacePrice($order->kind, $item->price, $item->ticket_type_id, $single, $adultId)
            ?? throw ResaleException::notListable('価格が設定されていません。');
    }
}
