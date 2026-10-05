<?php

namespace App\Services\Admin;

use App\Enums\Allocation;
use App\Enums\TicketStatus;
use App\Exceptions\AllocationChangeException;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchBlockAllocationChange;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * 管理者による試合×ブロックの割当変更。全件適用か全件拒否。
 *
 * ロック順は購入側と揃える: orders → allocation 行 → tickets。ここでは allocation 行を block_id 昇順に FOR UPDATE する。
 * 購入側は allocation ロック保持中にしかチケットを consuming にしないので、ロック後の tickets の数は確定する（tickets はロックしない）。
 *
 * 最上位のトランザクションとして呼ぶこと。SET LOCAL lock_timeout が外側のトランザクションに残り、
 * 55P03 で abort したトランザクションを外側が引き継いでしまうため、トランザクション内からの呼び出しは LogicException にする。
 */
class AllocationChangeService
{
    /**
     * @param  array<int, array{block_id: int, expected: Allocation|string, allocation: Allocation|string}>  $changes
     *
     * @throws InvalidArgumentException 入力が不正（空、block_id 重複、no-op、enum 外、その試合に行が無い）
     * @throws AuthorizationException $admin が管理者でない
     * @throws LogicException トランザクション内から呼ばれた
     * @throws AllocationChangeException
     */
    public function apply(User $admin, FootballMatch $match, array $changes): AllocationChangeResult
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('割当の変更は管理者のみ可能です。');
        }
        if (DB::transactionLevel() > 0) {
            throw new LogicException('AllocationChangeService::apply は最上位のトランザクションとして呼ぶこと。');
        }

        $normalized = $this->normalize($changes);

        try {
            return DB::transaction(fn () => $this->applyLocked($admin, $match, $normalized), 3);
        } catch (QueryException $e) {
            // Laravel の再試行は deadlock / 40001 のみ。lock_not_available は再試行されない
            if ($e->getCode() === '55P03') {
                throw AllocationChangeException::busy();
            }
            throw $e;
        }
    }

    /**
     * 全ブロックの consuming チケット数。
     *
     * @return array<int, array{paid: int, held: int}> block_id => 件数（チケットが無いブロックは含まない）
     */
    public function ticketCounts(FootballMatch $match, CarbonImmutable $now, ?array $blockIds = null): array
    {
        $query = Ticket::consumingInventory($now)->where('match_id', $match->id);
        if ($blockIds !== null) {
            $query->whereIn('block_id', $blockIds);
        }

        $counts = [];
        foreach ($query->selectRaw('block_id, status, count(*) as n')->groupBy('block_id', 'status')->get() as $row) {
            $counts[$row->block_id] ??= ['paid' => 0, 'held' => 0];
            $key = $row->status === TicketStatus::Paid ? 'paid' : 'held';
            $counts[$row->block_id][$key] += (int) $row->n;
        }

        return $counts;
    }

    /**
     * @param  array<int, array{block_id: int, expected: Allocation, allocation: Allocation}>  $changes
     */
    private function applyLocked(User $admin, FootballMatch $match, array $changes): AllocationChangeResult
    {
        $timeout = (int) config('ticketing.admin_lock_timeout_ms');
        if ($timeout < 1) {
            $timeout = 3000;
        }
        DB::statement("SET LOCAL lock_timeout = '{$timeout}ms'");

        $blockIds = array_column($changes, 'block_id');
        $rows = MatchBlockAllocation::where('match_id', $match->id)
            ->whereIn('block_id', $blockIds)
            ->orderBy('block_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('block_id');

        $now = CarbonImmutable::now();

        foreach ($blockIds as $blockId) {
            if (! $rows->has($blockId)) {
                throw new InvalidArgumentException("この試合に block_id {$blockId} の割当行がありません。");
            }
        }

        // キックオフ時刻は購入側の判定と同じく、ロック後に取った時刻で比べる
        $kickoffAt = FootballMatch::whereKey($match->id)->value('kickoff_at');
        if ($kickoffAt !== null && CarbonImmutable::parse($kickoffAt)->lte($now)) {
            throw AllocationChangeException::matchLocked();
        }

        $counts = $this->ticketCounts($match, $now, $blockIds);

        $conflicts = [];
        foreach ($changes as $change) {
            $blockId = $change['block_id'];
            $current = $rows[$blockId]->allocation;
            $tickets = $counts[$blockId] ?? ['paid' => 0, 'held' => 0];

            $reason = null;
            if ($current !== $change['expected']->value) {
                $reason = 'stale';
            } elseif ($tickets['paid'] + $tickets['held'] > 0) {
                $reason = 'has_tickets';
            }
            if ($reason !== null) {
                $conflicts[] = ['block_id' => $blockId, 'reason' => $reason, 'current' => $current, 'tickets' => $tickets];
            }
        }
        if ($conflicts !== []) {
            throw AllocationChangeException::conflict($conflicts);
        }

        $changeSetId = (string) Str::uuid();
        $changed = [];
        foreach ($changes as $change) {
            $blockId = $change['block_id'];
            $rows[$blockId]->update(['allocation' => $change['allocation']->value]);
            MatchBlockAllocationChange::create([
                'match_id' => $match->id,
                'block_id' => $blockId,
                'from_allocation' => $change['expected']->value,
                'to_allocation' => $change['allocation']->value,
                'changed_by' => $admin->id,
                'change_set_id' => $changeSetId,
                'changed_at' => $now,
            ]);
            $changed[] = ['block_id' => $blockId, 'from' => $change['expected'], 'to' => $change['allocation']];
        }

        return new AllocationChangeResult($changeSetId, $now, $changed);
    }

    /**
     * @return array<int, array{block_id: int, expected: Allocation, allocation: Allocation}> block_id 昇順
     */
    private function normalize(array $changes): array
    {
        if ($changes === []) {
            throw new InvalidArgumentException('変更が空です。');
        }

        $result = [];
        foreach ($changes as $change) {
            $blockId = $change['block_id'] ?? null;
            if (! is_int($blockId)) {
                throw new InvalidArgumentException('block_id が不正です。');
            }
            if (isset($result[$blockId])) {
                throw new InvalidArgumentException("block_id {$blockId} が重複しています。");
            }

            $expected = $this->toAllocation($change['expected'] ?? null);
            $allocation = $this->toAllocation($change['allocation'] ?? null);
            if ($expected === $allocation) {
                throw new InvalidArgumentException("block_id {$blockId} は変更がありません。");
            }

            $result[$blockId] = ['block_id' => $blockId, 'expected' => $expected, 'allocation' => $allocation];
        }
        ksort($result);

        return array_values($result);
    }

    private function toAllocation(mixed $value): Allocation
    {
        if ($value instanceof Allocation) {
            return $value;
        }
        $enum = is_string($value) ? Allocation::tryFrom($value) : null;
        if ($enum === null) {
            throw new InvalidArgumentException('allocation の値が不正です。');
        }

        return $enum;
    }
}
