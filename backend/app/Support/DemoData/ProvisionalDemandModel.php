<?php

namespace App\Support\DemoData;

/** 暫定の需要。相手の人気度による販売率だけで、成績や完売の打ち切りは入れていない */
final class ProvisionalDemandModel implements DemandModel
{
    private const SALE_RATE_BY_POPULARITY = [1 => 0.50, 2 => 0.60, 3 => 0.72, 4 => 0.85, 5 => 0.97];

    private const AWAY_FACTOR = 0.35;

    public function targetSold(MatchContext $match, BlockInfo $block, string $allocation, SeededRandom $rng): int
    {
        $rate = self::SALE_RATE_BY_POPULARITY[$match->popularity()] ?? 0.65;
        $factor = $allocation === 'away' ? self::AWAY_FACTOR : 1.0;

        return (int) min($block->capacity, round($block->capacity * $rate * $factor));
    }

    public function orderSize(MatchContext $match, BlockInfo $block, SeededRandom $rng): int
    {
        return (int) ($block->categoryType === 'main'
            ? $rng->weighted([1 => 10, 2 => 25, 3 => 30, 4 => 35])
            : $rng->weighted([1 => 40, 2 => 30, 3 => 20, 4 => 10]));
    }

    public function ticketTypesForOrder(MatchContext $match, BlockInfo $block, bool $isSeasonPassHolder, int $quantity, SeededRandom $rng): array
    {
        $types = [];
        for ($i = 0; $i < $quantity; $i++) {
            $types[] = match (true) {
                $isSeasonPassHolder && $rng->chance(0.40) => 'season_pass',
                $rng->chance($block->categoryType === 'main' ? 0.25 : 0.08) => 'youth',
                default => 'adult',
            };
        }

        return $types;
    }

    public function leadMinutes(MatchContext $match, SeededRandom $rng): int
    {
        $days = $rng->chance(0.60) ? $rng->int(0, 14) : $rng->int(15, 90);

        return $days * 1440 + $rng->int(0, 1439);
    }
}
