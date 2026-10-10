<?php

namespace App\Support\DemoData;

/** DB の seasons 行とシーズン定義の対応。index は DemoScenario::seasons() の並び（古い順） */
final readonly class SeasonSpan
{
    public function __construct(
        public int $index,
        public SeasonDefinition $definition,
        public int $seasonId,
        public string $startsOn,
        public string $endsOn,
    ) {}

    /** seasons.starts_on の 0 時（UTC として扱う） */
    public function startEpoch(): int
    {
        return (int) strtotime($this->startsOn.' 00:00:00 UTC');
    }

    public function endEpoch(): int
    {
        return (int) strtotime($this->endsOn.' 23:59:59 UTC');
    }
}
