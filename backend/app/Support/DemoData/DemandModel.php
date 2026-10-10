<?php

namespace App\Support\DemoData;

/**
 * 需要の差し込み口。注文の量・中身・時期はすべてここから決まる。
 * 乱数は呼び出し側が渡す SeededRandom だけを使う（決定性のため）。
 */
interface DemandModel
{
    /**
     * ブロックの販売目標枚数。capacity を超えてよいが、呼び出し側が capacity と座席数で切る
     *
     * @param  string  $allocation  home / away
     */
    public function targetSold(MatchContext $match, BlockInfo $block, string $allocation, SeededRandom $rng): int;

    /** 1 注文あたりの枚数 */
    public function orderSize(MatchContext $match, BlockInfo $block, SeededRandom $rng): int;

    /**
     * 1 注文ぶんの券種 code（adult / youth / season_pass）。長さは $quantity。
     * season_pass は年パス会員（$isSeasonPassHolder）だけが指定でき、1 ユーザー 1 試合 1 枚までは呼び出し側が保証する
     * （超えた分は adult にされる）
     *
     * @return list<string>
     */
    public function ticketTypesForOrder(MatchContext $match, BlockInfo $block, bool $isSeasonPassHolder, int $quantity, SeededRandom $rng): array;

    /** キックオフの何分前に買うか */
    public function leadMinutes(MatchContext $match, SeededRandom $rng): int;
}
