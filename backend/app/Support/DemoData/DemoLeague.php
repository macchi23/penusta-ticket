<?php

namespace App\Support\DemoData;

final readonly class DemoLeague
{
    /** @param  list<SeasonResult>  $seasons */
    public function __construct(public int $baseSeed, public array $seasons) {}

    public function season(string $name): SeasonResult
    {
        foreach ($this->seasons as $season) {
            if ($season->definition->name === $name) {
                return $season;
            }
        }

        throw new \InvalidArgumentException("unknown season: {$name}");
    }
}
