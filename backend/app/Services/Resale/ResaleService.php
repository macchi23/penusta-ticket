<?php

namespace App\Services\Resale;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleHoldStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ResaleException;
use App\Models\FootballMatch;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleHold;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * チケット 1 枚ずつの出品と、仮押さえなしの即時購入（暫定。仮押さえ経由は ResaleCartService）。
 *
 * ロック順はすべての操作で「元チケット FOR UPDATE → 出品行 FOR UPDATE」に揃える（出品行は ticket_id が不変なので、
 * ロックなしで読んで ticket_id を得てから取る）。expireDue は出品行だけを更新し、チケットを掴まない。
 *
 * allocation 行はロックしない。購入は paid を 1 枚 resold にして 1 枚 paid を作るだけで consuming 件数が変わらず、
 * チケットのあるブロックは割当変更できない（AllocationChangeService の has_tickets）ので、管理者の変更とも競合しない。
 */
class ResaleService
{
    public function __construct(
        private ResaleWindow $window,
        private ResaleSettlement $settlement,
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

            $sellerFee = self::sellerFee($price);
            $payout = self::payout($price);
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

    /**
     * 取り下げを拒む理由。active なら期間外(締切後で失効前、on_sale 以外)でも可、有効な仮押さえがあれば不可。
     * cancel() の検証と API の can_cancel / canCancel が共有する。
     */
    public function cancelBlocker(ResaleListingStatus $status, bool $held): ?ResaleException
    {
        if ($status !== ResaleListingStatus::Active) {
            return ResaleException::listingUnavailable();
        }

        return $held ? ResaleException::listingHeld() : null;
    }

    public function canCancel(ResaleListingStatus $status, bool $held): bool
    {
        return $this->cancelBlocker($status, $held) === null;
    }

    /**
     * 出品の定価と買い手の券種 id。年パス券は単券の大人価格から割り引く。
     * 算出できない（価格や大人券種が無い）ときは null。
     *
     * @return array{0: int, 1: int}|null [定価, 買い手の券種 id]
     */
    public static function listingFacePrice(OrderKind $kind, int $itemPrice, int $itemTypeId, ?int $singleAdultPrice, ?int $adultTypeId): ?array
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

    /** 出品者の手数料（円）。デモデータ生成器も同じ式を使う */
    public static function sellerFee(int $price): int
    {
        return (int) round(round($price * (float) config('ticketing.resale.seller_fee_rate'), 6));
    }

    /** 出品者への振込額 = 価格 − 出品者手数料 − 振込手数料。負なら出品できない価格 */
    public static function payout(int $price): int
    {
        return $price - self::sellerFee($price) - (int) config('ticketing.resale.transfer_fee');
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
            // 時刻はロック取得後に取る
            $now = CarbonImmutable::now();
            $held = $locked->status === ResaleListingStatus::Active
                && ResaleHold::live($now)->where('listing_id', $locked->id)->exists();
            if (($blocker = $this->cancelBlocker($locked->status, $held)) !== null) {
                throw $blocker;
            }

            $locked->status = ResaleListingStatus::Cancelled;
            $locked->save();

            return $locked;
        }, 3);
    }

    /**
     * 出品を即時確定で買う。元チケットは resold、買い手に新しいチケットと resale の注文ができる。
     * 仮押さえ経由の購入（ResaleCartService::checkout）に置き換える暫定の入口。
     *
     * @throws ResaleException
     */
    public function purchase(User $buyer, ResaleListing $listing): ResaleListing
    {
        return $this->settlement->translatingLockTimeout(fn () => $this->purchaseInTransaction($buyer, $listing));
    }

    private function purchaseInTransaction(User $buyer, ResaleListing $listing): ResaleListing
    {
        return DB::transaction(function () use ($buyer, $listing) {
            // ロック待ちを長引かせない
            $this->settlement->setLockTimeout();

            $head = ResaleListing::whereKey($listing->id)->first(['id', 'ticket_id', 'match_id'])
                ?? throw ResaleException::listingUnavailable();

            $this->settlement->lockBuyer($buyer, $head->match_id);
            $this->settlement->assertWithinLimit($buyer, $head->match_id);

            $ticket = Ticket::whereKey($head->ticket_id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();
            $locked = ResaleListing::whereKey($head->id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();

            // ロック待ちの間に締切を跨ぎうるので、時刻はロック取得後に取る
            $now = CarbonImmutable::now();

            $this->settlement->assertPurchasable($buyer, $ticket, $locked, $now);
            // 暫定: 仮押さえの導入中、有効な仮押さえがある出品（自分のものを含む）は即時購入させない
            if (ResaleHold::live($now)->where('listing_id', $locked->id)->exists()) {
                throw ResaleException::listingUnavailable();
            }

            $this->settlement->settle($buyer, $ticket, $locked, $now);

            return $locked;
        }, 1); // 再試行しない: 決済をトランザクション内で呼ぶので、再試行は実決済で二重課金になりうる(ロック順は固定でデッドロックしない)
    }

    /**
     * 締切を過ぎた active の出品と、期限を過ぎた held の仮押さえを整理する。チケットは掴まない。
     * 正しさは時刻条件（closes_at / expires_at）で保証済みで、これは状態の整理。ロック中の行は次回に回す（SKIP LOCKED）。
     *
     * @return int 失効させた出品の件数
     */
    public function expireDue(CarbonImmutable $now): int
    {
        $stamp = $now->utc()->format('Y-m-d H:i:s.u');

        $count = DB::affectingStatement(
            'UPDATE resale_listings SET status = ?, updated_at = ? WHERE id IN ('
            .'SELECT id FROM resale_listings WHERE status = ? AND closes_at <= ? ORDER BY id FOR UPDATE SKIP LOCKED)',
            [ResaleListingStatus::Expired->value, $stamp, ResaleListingStatus::Active->value, $stamp],
        );

        DB::affectingStatement(
            'UPDATE resale_holds SET status = ?, updated_at = ? WHERE id IN ('
            .'SELECT id FROM resale_holds WHERE status = ? AND expires_at <= ? ORDER BY id FOR UPDATE SKIP LOCKED)',
            [ResaleHoldStatus::Expired->value, $stamp, ResaleHoldStatus::Held->value, $stamp],
        );

        return $count;
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
