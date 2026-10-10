<?php

namespace App\Support\DemoData;

final readonly class MatchContext
{
    /** @param  array<string, mixed>  $attributes  matches.attributes */
    public function __construct(
        public int $matchId,
        public int $kickoffAt,
        public array $attributes,
        public SeasonSpan $season,
    ) {}

    public function popularity(): int
    {
        return (int) ($this->attributes['opponent_popularity'] ?? 3);
    }
}
