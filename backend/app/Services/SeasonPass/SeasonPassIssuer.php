<?php

namespace App\Services\SeasonPass;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Exceptions\SeasonPassIssueException;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Season;
use App\Models\SeasonPassSeat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Purchase\DbHoldExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 年パス固定席（season_pass_seats）を、試合ごとの paid チケットとして発行する。
 *
 * 発行側が新規に作る orders は他から見えないので、ロック順（orders → allocation 行 → tickets）のうち
 * orders は取らず、allocation 行（block_id 昇順、すべて FOR UPDATE）→ tickets の順にする。
 * 購入側（reserved は FOR SHARE）と違い reserved も FOR UPDATE にするのは、
 * 「一般チケットが無いこと」の確認から挿入までの間に購入が割り込まないようにするため。
 */
class SeasonPassIssuer
{
    public function __construct(private DbHoldExpiry $holdExpiry) {}

    /**
     * @return int 発行したチケット数（発行済みの席は飛ばす）
     *
     * @throws SeasonPassIssueException
     */
    public function issueForMatch(FootballMatch $match): int
    {
        if (! SeasonPassSeat::where('season_id', $match->season_id)->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($match) {
            $seats = SeasonPassSeat::with(['block', 'seat'])
                ->where('season_id', $match->season_id)
                ->orderBy('id')
                ->get();

            $allocations = [];
            foreach ($seats->pluck('block_id')->unique()->sort() as $blockId) {
                $allocations[$blockId] = MatchBlockAllocation::where('match_id', $match->id)
                    ->where('block_id', $blockId)
                    ->lockForUpdate()
                    ->value('allocation') ?? 'closed';
            }

            // ロック待ちの間に時刻が進みうるので、ロック取得後に取る
            $now = CarbonImmutable::now();

            $issued = Ticket::where('match_id', $match->id)
                ->whereIn('season_pass_seat_id', $seats->pluck('id'))
                ->pluck('season_pass_seat_id')
                ->flip();
            $pending = $seats->reject(fn (SeasonPassSeat $s) => $issued->has($s->id));
            if ($pending->isEmpty()) {
                return 0;
            }

            foreach ($pending as $seat) {
                $this->assertRowConsistent($seat);
            }

            foreach ($pending->pluck('block_id')->unique() as $blockId) {
                if ($allocations[$blockId] !== 'home') {
                    throw SeasonPassIssueException::blockNotHome($blockId, $match->id, $allocations[$blockId]);
                }
            }

            foreach ($pending->groupBy('block_id') as $blockId => $group) {
                $block = $group->first()->block;
                if ($block->seating_type === 'reserved') {
                    $this->assertSeatsFree($match, $group->pluck('seat_id')->filter()->all(), $now);

                    continue;
                }
                $consumed = Ticket::where('match_id', $match->id)
                    ->where('block_id', $blockId)
                    ->consumingInventory($now)
                    ->count();
                if ($consumed + $group->count() > $block->capacity) {
                    throw SeasonPassIssueException::overCapacity($match->id, $blockId, $consumed, $group->count(), $block->capacity);
                }
            }

            $typeId = TicketType::where('code', 'season_pass')->value('id')
                ?? throw SeasonPassIssueException::ticketTypeMissing();

            foreach ($pending as $seat) {
                $order = Order::create([
                    'user_id' => $seat->user_id,
                    'match_id' => $match->id,
                    'status' => OrderStatus::Paid,
                    'kind' => OrderKind::SeasonPass,
                    'sale_phase_id' => null,
                    'total' => 0,
                    'channel' => 'system',
                    'ordered_at' => $now,
                ]);
                $ticket = Ticket::create([
                    'match_id' => $match->id,
                    'block_id' => $seat->block_id,
                    'seat_id' => $seat->seat_id,
                    'season_pass_seat_id' => $seat->id,
                    'status' => TicketStatus::Paid,
                    'qr_uuid' => (string) Str::uuid(),
                ]);
                OrderItem::create([
                    'order_id' => $order->id,
                    'ticket_id' => $ticket->id,
                    'ticket_type_id' => $typeId,
                    'price' => 0,
                ]);
            }

            return $pending->count();
        }, 3);
    }

    /**
     * シーズンの全試合を、フェーズの展開込みで発行する（試合ごとに 1 トランザクション）。
     *
     * @return int 発行したチケット数
     */
    public function issueForSeason(Season $season): int
    {
        // SalePhaseProvisioner が本クラスに依存するので、循環を避けて実行時に解決する
        $provisioner = app(SalePhaseProvisioner::class);

        $total = 0;
        foreach ($season->matches()->orderBy('kickoff_at')->orderBy('id')->get() as $match) {
            $total += $provisioner->provision($match);
        }

        return $total;
    }

    private function assertRowConsistent(SeasonPassSeat $row): void
    {
        $reserved = $row->block->seating_type === 'reserved';
        if ($reserved && $row->seat_id === null) {
            throw SeasonPassIssueException::inconsistentRow($row->id, '指定席ブロックなのに seat_id がありません');
        }
        if (! $reserved && $row->seat_id !== null) {
            throw SeasonPassIssueException::inconsistentRow($row->id, '自由席ブロックなのに seat_id があります');
        }
        if ($row->seat_id !== null && $row->seat->block_id !== $row->block_id) {
            throw SeasonPassIssueException::inconsistentRow($row->id, '席が別のブロックに属しています');
        }
    }

    /** @param  list<int>  $seatIds */
    private function assertSeatsFree(FootballMatch $match, array $seatIds, CarbonImmutable $now): void
    {
        foreach ($seatIds as $seatId) {
            // 期限切れの held が残っていると部分 UNIQUE に当たるので、先に expired にする
            $this->holdExpiry->releaseStaleSeat($match->id, $seatId, $now);
        }

        $taken = Ticket::where('match_id', $match->id)
            ->whereIn('seat_id', $seatIds)
            ->consumingInventory($now)
            ->value('seat_id');
        if ($taken !== null) {
            throw SeasonPassIssueException::seatTaken($match->id, $taken);
        }
    }
}
