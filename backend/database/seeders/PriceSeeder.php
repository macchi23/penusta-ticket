<?php

namespace Database\Seeders;

use App\Models\FootballMatch;
use App\Models\MatchPrice;
use App\Models\Season;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\TicketType;
use App\Support\DemoData\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PriceSeeder extends Seeder
{
    /** 基準（2025 シーズン）の価格。カテゴリー名 => [大人, 小中高, 年パス引換] */
    public const BASE_PRICES = [
        'カテゴリー1' => [6000, 3000, 0],
        'カテゴリー2' => [5000, 2500, 0],
        'カテゴリー3' => [4500, 2200, 0],
        'ゴール裏' => [3500, 1500, 0],
    ];

    /** シーズン名 => 大人の改定額（基準 2025 からの差）。小中高は大人と同じ比率で動かす */
    public const SEASON_ADJUSTMENTS = [
        '2021' => -300,
        '2022' => -300,
        '2023' => -200,
        '2024' => -100,
        '2025' => 0,
        '2026 ハーフ' => 200,
        '2026-27' => 300,
    ];

    public const BIG_MATCH_SURCHARGE = 500;

    /** promo の大人料金の割引率 */
    public const PROMO_DISCOUNT = 0.15;

    /** シーズンごとの promo 試合数（ハーフは 1） */
    public const PROMO_PER_SEASON = 2;

    public const PROMO_PER_HALF_SEASON = 1;

    /** @var Collection<string, SeatCategory>|null */
    private ?Collection $categories = null;

    /** @var Collection<int, TicketType>|null */
    private ?Collection $ticketTypes = null;

    public function run(): void
    {
        TicketType::create(['name' => '大人', 'code' => 'adult', 'display_order' => 1]);
        TicketType::create(['name' => '小中高', 'code' => 'youth', 'display_order' => 2]);
        TicketType::create(['name' => '年パス引換', 'code' => 'season_pass', 'display_order' => 3]);

        $this->assignPromos();

        foreach (FootballMatch::with('season')->get() as $match) {
            $this->priceMatch($match);
        }
    }

    /** シーズンごとに promo の試合を乱数で選び、attributes.promo を立てる。ビッグマッチは候補にしない */
    private function assignPromos(): void
    {
        $seed = MatchSeeder::league()->baseSeed;

        foreach (Season::orderBy('id')->get() as $season) {
            $candidates = FootballMatch::where('season_id', $season->id)->orderBy('kickoff_at')->get()
                ->filter(fn (FootballMatch $m) => ($m->attributes['opponent_popularity'] ?? 1) < 5)
                ->values()->all();
            $count = $season->format === 'half' ? self::PROMO_PER_HALF_SEASON : self::PROMO_PER_SEASON;

            foreach (array_slice((new SeededRandom($seed, "promo:{$season->name}"))->shuffle($candidates), 0, $count) as $match) {
                $match->update(['attributes' => ['promo' => true] + $match->attributes]);
            }
        }
    }

    public function priceMatch(FootballMatch $match): void
    {
        $this->categories ??= SeatCategory::where('stadium_id', Stadium::firstOrFail()->id)->get()->keyBy('name');
        $this->ticketTypes ??= TicketType::whereIn('code', ['adult', 'youth', 'season_pass'])->orderBy('display_order')->get();

        $adjustment = self::SEASON_ADJUSTMENTS[$match->season->name] ?? 0;
        $isBigMatch = ($match->attributes['opponent_popularity'] ?? 1) >= 5;
        $isPromo = ($match->attributes['promo'] ?? false) === true;
        $now = now();
        $rows = [];

        foreach (self::BASE_PRICES as $categoryName => $prices) {
            $ratio = ($prices[0] + $adjustment) / $prices[0];

            foreach ($this->ticketTypes as $index => $ticketType) {
                $price = $prices[$index];
                if ($price > 0) {
                    // 大人は差額をそのまま、小中高は大人と同じ比率で動かす
                    $price = $index === 0 ? $price + $adjustment : self::roundToHundred($price * $ratio);
                    if ($isBigMatch) {
                        $price += self::BIG_MATCH_SURCHARGE;
                    }
                    if ($isPromo && $index === 0) {
                        $price = self::roundToHundred($price * (1 - self::PROMO_DISCOUNT));
                    }
                }

                $rows[] = [
                    'match_id' => $match->id,
                    'seat_category_id' => $this->categories[$categoryName]->id,
                    'ticket_type_id' => $ticketType->id,
                    'price' => $price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        MatchPrice::insert($rows);
    }

    private static function roundToHundred(float $value): int
    {
        return (int) (round($value / 100) * 100);
    }
}
