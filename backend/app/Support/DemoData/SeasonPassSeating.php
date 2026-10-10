<?php

namespace App\Support\DemoData;

/**
 * 年パス会員の固定席。シーズンごとに、ゴール裏 65%・バック 25%・メイン 10% で割り振る。
 * 指定席（バック・メイン）は具体的な席、ゴール裏（自由席）はブロックごとの枠として持つ。
 * 固定席は一般販売に出さない（引き換えなくても空席のまま）。
 */
final class SeasonPassSeating
{
    public const GOAL_BACK_SHARE = 0.65;

    public const BACK_SHARE = 0.25;

    /** @var array<int, int> ユーザー添字 => ブロック id */
    private array $blockOf = [];

    /** @var array<int, int> ユーザー添字 => 座席 id（指定席のみ） */
    private array $seatOf = [];

    /** @var array<int, int> ブロック id => 固定数 */
    private array $fixedCount = [];

    /** @var array<int, true> 固定された座席 id */
    private array $fixedSeats = [];

    /**
     * @param  list<int>  $holders  年パス会員のユーザー添字
     * @param  array<int, BlockInfo>  $blocks  block id => ブロック
     * @param  array<int, list<int>>  $seatIds  block id => 座席 id（昇順）
     * @param  list<int>  $alwaysHomeBlockIds  固定席を置けるブロック（どの試合でも home のもの。割当が試合で変わるブロックは除く）
     */
    public function __construct(array $holders, array $blocks, array $seatIds, array $alwaysHomeBlockIds, SeededRandom $rng)
    {
        [$goalBack, $back, $main] = self::split(count($holders));
        $holders = $rng->shuffle($holders);

        $allowed = array_flip($alwaysHomeBlockIds);
        $goalBlocks = array_values(array_filter($alwaysHomeBlockIds, fn (int $id) => ($blocks[$id]->categoryType ?? '') === 'goal_back'));
        sort($goalBlocks);
        $cursor = 0;
        $assigned = 0;
        for ($i = 0; $i < $goalBack && $goalBlocks !== []; $i++) {
            // 満杯のブロックは飛ばす（枠はブロックの capacity まで）
            for ($try = 0; $try < count($goalBlocks); $try++) {
                $blockId = $goalBlocks[$cursor++ % count($goalBlocks)];
                if (($this->fixedCount[$blockId] ?? 0) < $blocks[$blockId]->capacity) {
                    $this->blockOf[$holders[$assigned++]] = $blockId;
                    $this->fixedCount[$blockId] = ($this->fixedCount[$blockId] ?? 0) + 1;
                    break;
                }
            }
        }

        foreach (['general' => $back, 'main' => $main] as $categoryType => $count) {
            $pool = [];
            foreach ($blocks as $block) {
                if (isset($allowed[$block->id]) && $block->seatingType === 'reserved' && $block->categoryType === $categoryType) {
                    foreach ($seatIds[$block->id] ?? [] as $seatId) {
                        $pool[] = [$block->id, $seatId];
                    }
                }
            }
            foreach (array_slice($rng->shuffle($pool), 0, $count) as [$blockId, $seatId]) {
                $index = $holders[$assigned++];
                $this->blockOf[$index] = $blockId;
                $this->seatOf[$index] = $seatId;
                $this->fixedCount[$blockId] = ($this->fixedCount[$blockId] ?? 0) + 1;
                $this->fixedSeats[$seatId] = true;
            }
        }
    }

    /**
     * 年パス会員数 => [ゴール裏, バック, メイン] の固定席数
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function split(int $holders): array
    {
        $goalBack = (int) round($holders * self::GOAL_BACK_SHARE);
        $back = (int) round($holders * self::BACK_SHARE);

        return [$goalBack, $back, $holders - $goalBack - $back];
    }

    /** 固定席を持つ会員（ユーザー添字の昇順） */
    public function holders(): array
    {
        $holders = array_keys($this->blockOf);
        sort($holders);

        return $holders;
    }

    public function blockOf(int $userIndex): int
    {
        return $this->blockOf[$userIndex];
    }

    public function seatOf(int $userIndex): ?int
    {
        return $this->seatOf[$userIndex] ?? null;
    }

    /** ブロックの固定席数（一般販売から除く数） */
    public function fixedCount(int $blockId): int
    {
        return $this->fixedCount[$blockId] ?? 0;
    }

    public function isFixedSeat(int $seatId): bool
    {
        return isset($this->fixedSeats[$seatId]);
    }
}
