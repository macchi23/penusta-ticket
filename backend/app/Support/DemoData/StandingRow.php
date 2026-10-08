<?php

namespace App\Support\DemoData;

final readonly class StandingRow
{
    /** @param  string  $form  直近 5 試合（古い順）。W / D / L */
    public function __construct(
        public int $teamId,
        public int $rank,
        public int $played,
        public int $won,
        public int $drawn,
        public int $lost,
        public int $goalsFor,
        public int $goalsAgainst,
        public int $points,
        public string $form,
    ) {}

    public function goalDifference(): int
    {
        return $this->goalsFor - $this->goalsAgainst;
    }
}
