<?php

namespace Tests\Concerns;

use App\Support\DemoData\BlockDemand;
use App\Support\DemoData\BlockInfo;
use App\Support\DemoData\DemandModel;
use App\Support\DemoData\MatchContext;
use App\Support\DemoData\ScenarioDemandModel;
use App\Support\DemoData\SeededRandom;

/** 完売を必ず起こすためのテスト用モデル。全ブロックで販売可能席数の $rate 倍を要求する（scale を掛けた後の倍率なので、小さい scale では大きく指定する） */
final class HighDemandModel implements DemandModel
{
    private readonly ScenarioDemandModel $inner;

    public function __construct(private readonly float $rate)
    {
        $this->inner = new ScenarioDemandModel;
    }

    public function matchNoise(SeededRandom $rng): float
    {
        return $this->inner->matchNoise($rng);
    }

    public function blockDemand(MatchContext $match, BlockInfo $block, string $allocation, float $noise): BlockDemand
    {
        return new BlockDemand($this->rate, 1.0);
    }

    public function matchIndex(MatchContext $match, float $noise): float
    {
        return 1.0;
    }

    public function orderSize(MatchContext $match, BlockInfo $block, SeededRandom $rng): int
    {
        return $this->inner->orderSize($match, $block, $rng);
    }

    public function ticketTypesForOrder(MatchContext $match, BlockInfo $block, int $quantity, SeededRandom $rng): array
    {
        return $this->inner->ticketTypesForOrder($match, $block, $quantity, $rng);
    }

    public function leadMinutes(float $index, SeededRandom $rng): int
    {
        return $this->inner->leadMinutes($index, $rng);
    }
}
