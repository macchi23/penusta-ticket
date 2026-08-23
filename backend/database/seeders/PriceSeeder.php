<?php

namespace Database\Seeders;

use App\Models\FootballMatch;
use App\Models\MatchPrice;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\TicketType;
use Illuminate\Database\Seeder;

class PriceSeeder extends Seeder
{
    public function run(): void
    {
        $adult = TicketType::create(['name' => '大人', 'code' => 'adult', 'display_order' => 1]);
        $youth = TicketType::create(['name' => '小中高', 'code' => 'youth', 'display_order' => 2]);
        $seasonPass = TicketType::create(['name' => '年パス引換', 'code' => 'season_pass', 'display_order' => 3]);

        $stadium = Stadium::firstOrFail();
        $categories = SeatCategory::where('stadium_id', $stadium->id)->get()->keyBy('name');

        // カテゴリー名 => [大人, 小中高, 年パス引換]
        $basePrices = [
            'カテゴリー1' => [6000, 3000, 0],
            'カテゴリー2' => [5000, 2500, 0],
            'カテゴリー3' => [4500, 2200, 0],
            'ゴール裏' => [3500, 1500, 0],
        ];

        $ticketTypes = [$adult, $youth, $seasonPass];

        $now = now();
        foreach (FootballMatch::all() as $match) {
            $isBigMatch = ($match->attributes['opponent_popularity'] ?? 1) >= 5;
            $rows = [];

            foreach ($basePrices as $categoryName => $prices) {
                $category = $categories[$categoryName];

                foreach ($ticketTypes as $index => $ticketType) {
                    $price = $prices[$index];
                    if ($price > 0 && $isBigMatch) {
                        $price += 500;
                    }

                    $rows[] = [
                        'match_id' => $match->id,
                        'seat_category_id' => $category->id,
                        'ticket_type_id' => $ticketType->id,
                        'price' => $price,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            MatchPrice::insert($rows);
        }
    }
}
