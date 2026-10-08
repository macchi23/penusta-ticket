<?php

namespace App\Support\DemoData;

final readonly class SeasonResult
{
    /**
     * @param  list<int>  $teamIds
     * @param  array<int, list<Fixture>>  $rounds  節番号 => 試合
     * @param  list<PlayedMatch>  $matches
     * @param  list<StandingRow>  $table  最終（2026-27 は AS_OF 時点）の順位表
     * @param  int  $attempts  棄却サンプリングの試行回数
     * @param  list<int>  $relegated  降格したチーム（春秋制のみ）
     * @param  list<int>  $promoted  昇格してきたチーム（春秋制のみ。次シーズンの構成に入る）
     */
    public function __construct(
        public SeasonDefinition $definition,
        public array $teamIds,
        public array $rounds,
        public array $matches,
        public array $table,
        public int $attempts,
        public int $penustaRank,
        public array $relegated = [],
        public array $promoted = [],
    ) {}
}
