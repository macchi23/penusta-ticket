<?php

namespace App\Support\DemoData;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 成績シナリオに連動する需要。試合の需要指数 d を、シーズンの水準・直近の調子・順位争い・相手・曜日・天気・価格から作り、
 * 席種ごとの感度（ゴール裏は鈍感、メインは敏感）で販売可能席数に対する割合にする。
 */
final class ScenarioDemandModel implements DemandModel
{
    /** シーズン名 => 水準 L */
    public const LEVEL = [
        '2021' => 0.62, '2022' => 0.70, '2023' => 0.73, '2024' => 0.76, '2025' => 0.92, '2026' => 0.92, '2026-27' => 0.90,
    ];

    public const FORM_SLOPE = 0.03;

    /** 直近 5 試合の勝点の平均的な値（1 試合あたり 1.4） */
    public const FORM_POINTS_PER_MATCH = 1.4;

    public const RACE_START = 0.66;

    public const RACE_TITLE_BONUS = 1.20;

    public const RACE_SURVIVAL_BONUS = 0.15;

    public const RACE_GAP = 6;

    public const RACE_SURVIVAL_RANK = 15;

    public const RACE_TITLE_RANK = 3;

    public const LATE_SLUMP_START = 0.79;

    public const LATE_SLUMP = 0.93;

    /** 相手の人気度 1〜5 */
    public const OPPONENT = [1 => 0.85, 2 => 0.92, 3 => 1.00, 4 => 1.10, 5 => 1.25];

    public const DAY_SATURDAY_NIGHT = 0.98;

    public const DAY_HOLIDAY = 1.08;

    public const DAY_WEEKDAY = 0.75;

    public const NIGHT_FROM_HOUR = 17;

    public const OPENER = 1.15;

    public const FINALE = 1.10;

    public const WEATHER_CLOUDY = 0.98;

    public const HOT_ABOVE = 30.0;

    public const HOT = 0.95;

    public const COLD_BELOW = 8.0;

    public const COLD = 0.93;

    public const PRICE_ELASTICITY = -0.8;

    public const NOISE_SD = 0.07;

    /** 席種ごとの [係数, 指数] */
    public const GOAL_BACK = [0.90, 0.6];

    public const MAIN = [0.70, 1.3];

    public const BACK = [0.75, 1.0];

    /** アウェー割当のブロック: 基礎 + 人気度 × 傾き */
    public const AWAY_BASE = 0.20;

    public const AWAY_PER_POPULARITY = 0.12;

    /** リードタイムの平均（日）= 基礎 + 傾き × min(d, 上限) */
    public const LEAD_BASE_DAYS = 5.0;

    public const LEAD_DAYS_PER_INDEX = 12.0;

    public const LEAD_INDEX_CAP = 1.3;

    public function matchNoise(SeededRandom $rng): float
    {
        return exp($rng->normal(0.0, self::NOISE_SD));
    }

    public function matchIndex(MatchContext $match, float $noise): float
    {
        return $this->index($match, 1.0, $noise);
    }

    public function blockDemand(MatchContext $match, BlockInfo $block, string $allocation, float $noise): BlockDemand
    {
        $ratio = $match->priceRatios[$block->seatCategoryId] ?? 1.0;
        $index = $this->index($match, $ratio, $noise);

        if ($allocation === 'away') {
            $rate = (self::AWAY_BASE + self::AWAY_PER_POPULARITY * $match->popularity()) * $this->weather($match);

            return new BlockDemand($rate, $index);
        }

        return new BlockDemand(self::saleRate($block->categoryType, $index), $index);
    }

    /** ホーム側のブロックの、需要指数 d に対する制約なしの需要割合 */
    public static function saleRate(string $categoryType, float $index): float
    {
        [$coefficient, $exponent] = match ($categoryType) {
            'goal_back' => self::GOAL_BACK,
            'main' => self::MAIN,
            default => self::BACK,
        };

        return $coefficient * $index ** $exponent;
    }

    /** 試合の需要指数 d。$priceRatio は大人価格 / 基準価格 */
    public function index(MatchContext $match, float $priceRatio, float $noise): float
    {
        return (self::LEVEL[$match->season->definition->name] ?? 0.75)
            * $this->form($match)
            * $this->race($match)
            * (self::OPPONENT[$match->popularity()] ?? 1.0)
            * $this->day($match)
            * $this->weather($match)
            * $priceRatio ** self::PRICE_ELASTICITY
            * $noise;
    }

    public function form(MatchContext $match): float
    {
        $standing = $match->standing;
        if ($standing === null || $standing->form === '') {
            return 1.0;
        }

        // 5 試合に満たない序盤は、平均的な勝点との差で見る
        $expected = self::FORM_POINTS_PER_MATCH * strlen($standing->form);

        return 1.0 + self::FORM_SLOPE * ($standing->formPoints() - $expected);
    }

    public function race(MatchContext $match): float
    {
        $position = (float) ($match->attributes['round_ratio'] ?? 0.0);
        $standing = $match->standing;
        if ($standing === null) {
            return 1.0;
        }

        $late = ($position - self::RACE_START) / (1.0 - self::RACE_START);
        if ($position >= self::RACE_START) {
            if ($standing->rank <= self::RACE_TITLE_RANK && $standing->leaderPoints - $standing->points <= self::RACE_GAP) {
                return 1.0 + self::RACE_TITLE_BONUS * $late;
            }
            $inSurvivalFight = $standing->cutoffPoints !== null
                && ($standing->rank >= self::RACE_SURVIVAL_RANK || $standing->points - $standing->cutoffPoints <= self::RACE_GAP);
            if ($inSurvivalFight) {
                return 1.0 + self::RACE_SURVIVAL_BONUS * $late;
            }
        }

        return $position >= self::LATE_SLUMP_START ? self::LATE_SLUMP : 1.0;
    }

    public function day(MatchContext $match): float
    {
        $jst = (new DateTimeImmutable('@'.$match->kickoffAt))->setTimezone(new DateTimeZone(DemoScenario::TIMEZONE));
        $isNight = (int) $jst->format('G') >= self::NIGHT_FROM_HOUR;
        $weekday = (int) $jst->format('N');

        $factor = match (true) {
            ($match->attributes['is_holiday'] ?? false) === true => self::DAY_HOLIDAY,
            $weekday >= 6 => ($weekday === 6 && $isNight) ? self::DAY_SATURDAY_NIGHT : 1.0,
            default => self::DAY_WEEKDAY,
        };

        return $factor * ($match->isFirstHome ? self::OPENER : 1.0) * ($match->isLastHome ? self::FINALE : 1.0);
    }

    /** 雨・雪の効きは購入のタイミング側（ここでは 1.0）。気温は需要に効く */
    public function weather(MatchContext $match): float
    {
        $factor = ($match->attributes['weather'] ?? '') === WeatherCondition::Cloudy->value ? self::WEATHER_CLOUDY : 1.0;
        $temperature = $match->attributes['temperature'] ?? null;
        if (is_numeric($temperature)) {
            if ($temperature > self::HOT_ABOVE) {
                $factor *= self::HOT;
            } elseif ($temperature < self::COLD_BELOW) {
                $factor *= self::COLD;
            }
        }

        return $factor;
    }

    public function orderSize(MatchContext $match, BlockInfo $block, SeededRandom $rng): int
    {
        return (int) ($block->categoryType === 'main'
            ? $rng->weighted([1 => 10, 2 => 25, 3 => 30, 4 => 35])
            : $rng->weighted([1 => 40, 2 => 30, 3 => 20, 4 => 10]));
    }

    public function ticketTypesForOrder(MatchContext $match, BlockInfo $block, int $quantity, SeededRandom $rng): array
    {
        $types = [];
        for ($i = 0; $i < $quantity; $i++) {
            $types[] = $rng->chance($block->categoryType === 'main' ? 0.25 : 0.08) ? 'youth' : 'adult';
        }

        return $types;
    }

    public function leadMinutes(float $index, SeededRandom $rng): int
    {
        $meanDays = self::LEAD_BASE_DAYS + self::LEAD_DAYS_PER_INDEX * min($index, self::LEAD_INDEX_CAP);

        return (int) round(-$meanDays * 1440 * log(1.0 - $rng->float()));
    }
}
