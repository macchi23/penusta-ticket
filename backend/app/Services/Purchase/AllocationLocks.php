<?php

namespace App\Services\Purchase;

use App\Models\Block;
use App\Models\MatchBlockAllocation;

/**
 * 購入側が allocation 行をロックして値を読む。管理者の割当変更（block_id 昇順で FOR UPDATE）と競合しないための入口。
 *
 * ロックモードは seating_type で決め打ちする。
 * - reserved: FOR SHARE。二重販売は tickets の partial unique index が防ぐので、購入同士は並行できる
 * - unreserved: FOR UPDATE。capacity のカウントを購入同士で直列化する
 * 同一トランザクション内で同じ行を SHARE から UPDATE へ格上げしないこと（待ち合ってデッドロックする）。
 */
class AllocationLocks
{
    /**
     * block_id 昇順に 1 ブロックずつロックし、ロックした読み取りの値を返す。行が無いブロックは 'closed'。
     * 呼び出し側でカート行（orders）をロック済みであること（ロック順: orders → allocation 行 → tickets）。
     *
     * @param  iterable<Block>  $blocks
     * @return array<int, string> block_id => allocation
     */
    public function forPurchase(int $matchId, iterable $blocks): array
    {
        $sorted = [];
        foreach ($blocks as $block) {
            $sorted[$block->id] = $block;
        }
        ksort($sorted);

        $allocations = [];
        foreach ($sorted as $blockId => $block) {
            $query = MatchBlockAllocation::where('match_id', $matchId)->where('block_id', $blockId);
            $query = $block->seating_type === 'reserved' ? $query->sharedLock() : $query->lockForUpdate();
            $allocations[$blockId] = $query->value('allocation') ?? 'closed';
        }

        return $allocations;
    }
}
