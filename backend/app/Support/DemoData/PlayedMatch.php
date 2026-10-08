<?php

namespace App\Support\DemoData;

/** 試合と結果。AS_OF より後の試合は得点が null */
final readonly class PlayedMatch
{
    public function __construct(
        public Fixture $fixture,
        public ?int $homeGoals,
        public ?int $awayGoals,
    ) {}

    public function isPlayed(): bool
    {
        return $this->homeGoals !== null;
    }
}
