<?php

namespace App\Support\DemoData;

final readonly class MatchContext
{
    /**
     * @param  array<string, mixed>  $attributes  matches.attributes
     * @param  array<int, float>  $priceRatios  seat_category_id => 大人価格 / 2025 の基準価格
     */
    public function __construct(
        public int $matchId,
        public int $kickoffAt,
        public array $attributes,
        public SeasonSpan $season,
        public ?MatchStanding $standing = null,
        public array $priceRatios = [],
        public bool $isFirstHome = false,
        public bool $isLastHome = false,
    ) {}

    public function popularity(): int
    {
        return (int) ($this->attributes['opponent_popularity'] ?? 3);
    }
}
