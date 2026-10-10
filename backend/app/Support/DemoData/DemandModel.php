<?php

namespace App\Support\DemoData;

/**
 * 需要の差し込み口。注文の量・中身・時期はすべてここから決まる。
 * 乱数は呼び出し側が渡す SeededRandom だけを使う（決定性のため）。
 */
interface DemandModel
{
    /** 試合ごとの需要のぶれ（乗数）。1 試合に 1 回だけ引き、全ブロックで共有する */
    public function matchNoise(SeededRandom $rng): float;

    /**
     * ブロックの制約なしの需要。capacity では切らない（完売の打ち切りは呼び出し側）
     *
     * @param  string  $allocation  home / away
     */
    public function blockDemand(MatchContext $match, BlockInfo $block, string $allocation, float $noise): BlockDemand;

    /** 試合全体の需要指数（価格は基準のまま）。席種に紐づかない行動ログの時期に使う */
    public function matchIndex(MatchContext $match, float $noise): float;

    /** 1 注文あたりの枚数 */
    public function orderSize(MatchContext $match, BlockInfo $block, SeededRandom $rng): int;

    /**
     * 1 注文ぶんの一般販売の券種 code（adult / youth）。長さは $quantity。年パスの引換は一般販売では作らない
     *
     * @return list<string>
     */
    public function ticketTypesForOrder(MatchContext $match, BlockInfo $block, int $quantity, SeededRandom $rng): array;

    /** キックオフの何分前に買うか（販売開始での打ち切りは呼び出し側）。$index は需要指数 */
    public function leadMinutes(float $index, SeededRandom $rng): int;
}
