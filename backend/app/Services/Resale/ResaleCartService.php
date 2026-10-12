<?php

namespace App\Services\Resale;

use App\Enums\ResaleHoldStatus;
use App\Exceptions\PurchaseException;
use App\Exceptions\ResaleException;
use App\Models\ResaleHold;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * リセール出品の仮押さえ（resale_holds）。1 人 1 試合で有効な仮押さえは 1 件、1 回の仮押さえは 1 出品だけ。
 *
 * ロック順はすべての操作で「(buyer, match) の advisory → tickets → resale_listings → resale_holds」の部分列。
 * 上限判定は ResaleService::purchase と同じ advisory キーで直列化する。
 * 正しさは時刻条件（ResaleHold::live）で保証し、期限切れの行の expired への更新は整理（expireDue / 各操作の副作用）。
 * `now` は最後のロックを取った後に取り、結果オブジェクトで返す。
 */
class ResaleCartService
{
    public function __construct(private ResaleSettlement $settlement) {}

    /** 自分の有効な仮押さえ。書き換えもロックもしない。 */
    public function current(User $buyer, int $matchId): CurrentResaleHold
    {
        $now = CarbonImmutable::now();
        $hold = ResaleHold::live($now)
            ->where('user_id', $buyer->id)
            ->where('match_id', $matchId)
            ->with(['listing.ticket.seat', 'listing.block.stand', 'listing.block.seatCategory', 'listing.ticketType'])
            ->first();

        return new CurrentResaleHold($hold, $now);
    }

    /**
     * @throws ResaleException
     */
    public function hold(User $buyer, int $listingId): ResaleHoldResult
    {
        return $this->settlement->translatingLockTimeout(fn () => DB::transaction(function () use ($buyer, $listingId) {
            $this->settlement->setLockTimeout();

            $head = ResaleListing::whereKey($listingId)->first(['id', 'ticket_id', 'match_id'])
                ?? throw ResaleException::listingUnavailable();

            $this->settlement->lockBuyer($buyer, $head->match_id);
            $ticket = Ticket::whereKey($head->ticket_id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();
            $listing = ResaleListing::whereKey($head->id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();

            $now = CarbonImmutable::now();

            $this->settlement->assertPurchasable($buyer, $ticket, $listing, $now);

            // 期限切れの held を整理する（取り直しのため。有効かどうかは時刻条件で決まる）
            $this->expireStale('user_match', $buyer->id, $head->match_id, $now);
            $this->expireStale('listing', $listing->id, null, $now);

            $mine = ResaleHold::live($now)->where('user_id', $buyer->id)->where('match_id', $head->match_id)->first();
            if ($mine !== null) {
                if ($mine->listing_id === $listing->id) {
                    return new ResaleHoldResult($mine, false, $now);
                }
                throw ResaleException::holdExists();
            }

            // 押さえ直しの連発で出品を占有させない（期限切れ直後の行も対象）
            $cooldown = (int) config('ticketing.resale.hold_cooldown_minutes');
            $recentlyEnded = ResaleHold::query()
                ->where('user_id', $buyer->id)
                ->where('listing_id', $listing->id)
                // 期限切れの整理（expired への更新）は例外で巻き戻る / SKIP LOCKED で飛ばされることがあるので、期限切れの held も含める
                ->where(fn ($q) => $q
                    ->whereIn('status', [ResaleHoldStatus::Released->value, ResaleHoldStatus::Expired->value])
                    ->orWhere(fn ($q) => $q->where('status', ResaleHoldStatus::Held->value)->where('expires_at', '<=', $now)))
                // 終わった時刻: 解除は updated_at、期限切れは expires_at（整理が遅れても延びない）
                ->whereRaw("(CASE WHEN status = 'released' THEN updated_at ELSE expires_at END) > ?", [$now->subMinutes($cooldown)])
                ->exists();
            if ($recentlyEnded) {
                throw ResaleException::holdCooldown($cooldown);
            }

            if (ResaleHold::live($now)->where('listing_id', $listing->id)->exists()) {
                throw ResaleException::listingUnavailable();
            }

            $this->settlement->assertWithinLimit($buyer, $head->match_id);

            $expiresAt = $now->addMinutes((int) config('ticketing.hold_minutes'));
            if ($expiresAt > $listing->closes_at) {
                $expiresAt = CarbonImmutable::instance($listing->closes_at);
            }

            try {
                // 部分ユニークに当たったとき、外側のトランザクションを中断させないようセーブポイントで囲む
                $hold = DB::transaction(fn () => ResaleHold::create([
                    'listing_id' => $listing->id,
                    'user_id' => $buyer->id,
                    'match_id' => $head->match_id,
                    'status' => ResaleHoldStatus::Held,
                    'expires_at' => $expiresAt,
                ]));
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    throw ResaleException::listingUnavailable();
                }
                throw $e;
            }

            return new ResaleHoldResult($hold, true, $now);
        }, 3));
    }

    /**
     * @throws ModelNotFoundException 他人の仮押さえ・存在しない id
     */
    public function release(User $buyer, int $holdId): ResaleReleaseResult
    {
        return $this->settlement->translatingLockTimeout(fn () => DB::transaction(function () use ($buyer, $holdId) {
            $this->settlement->setLockTimeout();

            $head = ResaleHold::whereKey($holdId)->where('user_id', $buyer->id)->firstOrFail(['id', 'match_id']);

            $this->settlement->lockBuyer($buyer, $head->match_id);
            $hold = ResaleHold::whereKey($head->id)->lockForUpdate()->firstOrFail();

            $now = CarbonImmutable::now();

            $released = false;
            if ($hold->status === ResaleHoldStatus::Held) {
                $released = $hold->expires_at > $now;
                $hold->status = $released ? ResaleHoldStatus::Released : ResaleHoldStatus::Expired;
                $hold->save();
            }

            return new ResaleReleaseResult($hold, $released, $now);
        }, 3));
    }

    /**
     * 仮押さえを確定して購入する。決済失敗は全体を巻き戻す（仮押さえは held のまま）。
     *
     * @throws ResaleException
     * @throws PurchaseException cart_expired（仮押さえが切れている）
     * @throws ModelNotFoundException 他人の仮押さえ・存在しない id
     */
    public function checkout(User $buyer, int $holdId): ResaleCheckoutResult
    {
        $outcome = $this->settlement->translatingLockTimeout(fn () => DB::transaction(function () use ($buyer, $holdId) {
            $this->settlement->setLockTimeout();

            $head = ResaleHold::whereKey($holdId)->where('user_id', $buyer->id)->firstOrFail();

            $this->settlement->lockBuyer($buyer, $head->match_id);

            // 確定済みなら何も取り直さず返す（待たされない）
            $current = ResaleHold::findOrFail($head->id);
            if ($current->status === ResaleHoldStatus::Purchased) {
                return new ResaleCheckoutResult($current->order()->firstOrFail(), $current, false, CarbonImmutable::now());
            }

            $ticketId = ResaleListing::whereKey($current->listing_id)->value('ticket_id')
                ?? throw ResaleException::listingUnavailable();
            $ticket = Ticket::whereKey($ticketId)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();
            $listing = ResaleListing::whereKey($current->listing_id)->lockForUpdate()->first()
                ?? throw ResaleException::listingUnavailable();
            $hold = ResaleHold::whereKey($head->id)->lockForUpdate()->firstOrFail();

            $now = CarbonImmutable::now();

            if ($hold->status !== ResaleHoldStatus::Held || $hold->expires_at <= $now) {
                if ($hold->status === ResaleHoldStatus::Held) {
                    $hold->status = ResaleHoldStatus::Expired;
                    $hold->save();
                }

                // 期限切れの更新をコミットするため、例外はトランザクションの外で投げる
                return PurchaseException::cartExpired();
            }

            $this->settlement->assertPurchasable($buyer, $ticket, $listing, $now);
            $this->settlement->assertWithinLimit($buyer, $hold->match_id);

            $order = $this->settlement->settle($buyer, $ticket, $listing, $now);

            $hold->status = ResaleHoldStatus::Purchased;
            $hold->order_id = $order->id;
            $hold->save();

            return new ResaleCheckoutResult($order, $hold, true, $now);
        }, 1)); // 再試行しない: 決済をトランザクション内で呼ぶので、再試行は実決済で二重課金になりうる

        if ($outcome instanceof PurchaseException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * 期限切れの held を expired にする（expireDue と同じく SKIP LOCKED で、行ロックの交差を避ける）。
     * 取りこぼしは部分ユニーク（23505）で listing_unavailable に落ちる。
     * 部分ユニーク索引の待ち（XactLockTableWait）の交差は残りうるが、deadlock 検出（40P01）と hold の再試行で吸収する。
     *
     * @param  'user_match'|'listing'  $by
     */
    private function expireStale(string $by, int $id, ?int $matchId, CarbonImmutable $now): void
    {
        $stamp = $now->utc()->format('Y-m-d H:i:s.u');
        $cond = $by === 'listing' ? 'listing_id = ?' : 'user_id = ? AND match_id = ?';
        $bindings = $by === 'listing' ? [$id] : [$id, $matchId];

        DB::affectingStatement(
            'UPDATE resale_holds SET status = ?, updated_at = ? WHERE id IN ('
            ."SELECT id FROM resale_holds WHERE status = ? AND expires_at <= ? AND {$cond} ORDER BY id FOR UPDATE SKIP LOCKED)",
            [ResaleHoldStatus::Expired->value, $stamp, ResaleHoldStatus::Held->value, $stamp, ...$bindings],
        );
    }
}
