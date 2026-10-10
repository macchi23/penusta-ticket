<?php

namespace App\Support\DemoData;

/** シーズンの成績区分。ペヌスタの最終順位（シーズン定義の範囲の悪い側）で決める */
enum PerformanceTier: string
{
    case Relegation = 'relegation';
    case Mid = 'mid';
    case Title = 'title';

    public static function of(SeasonDefinition $definition): self
    {
        $ratio = $definition->minRank / $definition->teamCount;

        return match (true) {
            $ratio >= 0.7 => self::Relegation,
            $ratio <= 0.15 => self::Title,
            default => self::Mid,
        };
    }
}
