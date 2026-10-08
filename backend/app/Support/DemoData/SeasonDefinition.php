<?php

namespace App\Support\DemoData;

final readonly class SeasonDefinition
{
    /**
     * @param  float  $penustaAttack  ペヌスタの攻撃力の調整値（条件を満たしやすくするための定数）
     * @param  float  $penustaDefense  ペヌスタの守備力の調整値
     * @param  array{round: int, rank: int}|null  $lowRankFrom  この節以降にこの順位以下だった時期が要る
     * @param  int|null  $leaderFromRound  この節以降に首位だった時期が要る
     * @param  int|null  $maxGapToChampion  優勝チームとの勝点差の上限
     */
    public function __construct(
        public string $name,
        public SeasonFormat $format,
        public int $startYear,
        public int $teamCount,
        public int $minRank,
        public int $maxRank,
        public float $penustaAttack,
        public float $penustaDefense,
        public ?array $lowRankFrom = null,
        public ?int $leaderFromRound = null,
        public ?int $maxGapToChampion = null,
    ) {}

    public function roundCount(): int
    {
        return ($this->teamCount - 1) * 2;
    }
}
