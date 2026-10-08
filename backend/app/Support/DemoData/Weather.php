<?php

namespace App\Support\DemoData;

use DateTimeImmutable;
use DateTimeZone;

/** 日付（JST）から決定的に出す天気と気温。同じ日はどの試合も同じ天気 */
final class Weather
{
    /** 大阪の月別平年値（℃） */
    private const MONTHLY_NORMALS = [
        1 => 6.2, 2 => 6.6, 3 => 9.8, 4 => 15.1, 5 => 19.9, 6 => 23.7,
        7 => 27.4, 8 => 28.8, 9 => 24.6, 10 => 18.7, 11 => 13.2, 12 => 8.4,
    ];

    private const SNOW_PROBABILITY = 0.04;

    private const NIGHT_OFFSET = -2.0;

    public function __construct(private readonly int $baseSeed) {}

    public function forKickoff(DateTimeImmutable $kickoff): WeatherReport
    {
        $jst = $kickoff->setTimezone(new DateTimeZone(DemoScenario::TIMEZONE));
        $month = (int) $jst->format('n');
        $rng = new SeededRandom($this->baseSeed, 'weather:'.$jst->format('Y-m-d'));

        $precipitation = $rng->float();
        $sky = $rng->float();
        $offset = $rng->float() * 6.0 - 3.0;

        $rainProbability = in_array($month, [6, 7], true) ? 0.40 : 0.20;
        if (in_array($month, [12, 2], true) && $precipitation < self::SNOW_PROBABILITY) {
            $condition = WeatherCondition::Snowy;
        } elseif ($precipitation < $rainProbability) {
            $condition = WeatherCondition::Rainy;
        } else {
            $condition = $sky < 0.4 ? WeatherCondition::Cloudy : WeatherCondition::Sunny;
        }

        $temperature = self::MONTHLY_NORMALS[$month] + $offset;
        if ((int) $jst->format('G') >= 17) {
            $temperature += self::NIGHT_OFFSET;
        }

        return new WeatherReport($condition, round($temperature, 1));
    }
}
