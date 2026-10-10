<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Seat;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\Stand;
use Illuminate\Database\Seeder;

class StadiumSeeder extends Seeder
{
    /**
     * SVG viewbox is 1000x760. The pitch (105:68) occupies x:250-750, y:218-542.
     * Main y:90-190, back y:570-670, goal ends sit between them (y:195-565).
     */
    private const SIDE_W = 44;

    private const GOAL_H = 21;

    public function run(): void
    {
        $stadium = Stadium::create([
            'name' => 'ペヌソニックスタジアム',
            'short_name' => 'ペヌスタ',
        ]);

        $mainStand = Stand::create(['stadium_id' => $stadium->id, 'name' => 'メインスタンド', 'code' => 'MAIN']);
        $backStand = Stand::create(['stadium_id' => $stadium->id, 'name' => 'バックスタンド', 'code' => 'BACK']);
        $northStand = Stand::create(['stadium_id' => $stadium->id, 'name' => '北スタンド', 'code' => 'NORTH']);
        $southStand = Stand::create(['stadium_id' => $stadium->id, 'name' => '南スタンド', 'code' => 'SOUTH']);

        $category1 = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'カテゴリー1', 'display_order' => 1]);
        $category2 = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'カテゴリー2', 'display_order' => 2]);
        $category3 = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'カテゴリー3', 'display_order' => 3]);
        $goalBack = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'ゴール裏', 'display_order' => 4]);

        // メインスタンド: M1-M12 指定席。中央のM5-M8はカテゴリー1、それ以外はカテゴリー2。
        $mainCentralCodes = ['M5', 'M6', 'M7', 'M8'];
        foreach ($this->distributeHorizontal(210, 790, 12, self::SIDE_W) as $i => $x) {
            $code = 'M'.($i + 1);
            $this->createReservedBlock(
                stand: $mainStand,
                seatCategory: in_array($code, $mainCentralCodes, true) ? $category1 : $category2,
                code: $code,
                x: $x,
                y: 90,
            );
        }

        // バックスタンド: B1-B12 指定席（カテゴリー3）
        foreach ($this->distributeHorizontal(210, 790, 12, self::SIDE_W) as $i => $x) {
            $this->createReservedBlock(
                stand: $backStand,
                seatCategory: $category3,
                code: 'B'.($i + 1),
                x: $x,
                y: 570,
            );
        }

        // 北スタンド（ホームゴール裏）: N1-N16 自由席（ゴール裏カテゴリー）
        foreach ($this->distributeVertical(195, 565, 16, self::GOAL_H) as $i => $y) {
            $this->createUnreservedBlock(
                stand: $northStand,
                seatCategory: $goalBack,
                code: 'N'.($i + 1),
                x: 60,
                y: $y,
            );
        }

        // 南スタンド: S1-S16 自由席（ゴール裏カテゴリー、アウェー側になりうる）
        foreach ($this->distributeVertical(195, 565, 16, self::GOAL_H) as $i => $y) {
            $this->createUnreservedBlock(
                stand: $southStand,
                seatCategory: $goalBack,
                code: 'S'.($i + 1),
                x: 800,
                y: $y,
            );
        }
    }

    /**
     * @return array<int, float>
     */
    private function distributeHorizontal(float $start, float $end, int $count, float $w): array
    {
        $gap = ($end - $start - $count * $w) / max(1, $count - 1);

        $positions = [];
        for ($i = 0; $i < $count; $i++) {
            $positions[] = $start + $i * ($w + $gap);
        }

        return $positions;
    }

    /**
     * @return array<int, float>
     */
    private function distributeVertical(float $start, float $end, int $count, float $h): array
    {
        return $this->distributeHorizontal($start, $end, $count, $h);
    }

    private function createReservedBlock(Stand $stand, SeatCategory $seatCategory, string $code, float $x, float $y): void
    {
        $block = Block::create([
            'stand_id' => $stand->id,
            'seat_category_id' => $seatCategory->id,
            'code' => $code,
            'seating_type' => 'reserved',
            'capacity' => 500,
            'geometry' => ['x' => round($x, 2), 'y' => $y, 'w' => self::SIDE_W, 'h' => 100],
        ]);

        $rows = range('A', 'Y');
        $seats = [];
        $now = now();
        foreach ($rows as $row) {
            for ($number = 1; $number <= 20; $number++) {
                $seats[] = [
                    'block_id' => $block->id,
                    'row_label' => $row,
                    'number' => $number,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($seats, 500) as $chunk) {
            Seat::insert($chunk);
        }
    }

    private function createUnreservedBlock(Stand $stand, SeatCategory $seatCategory, string $code, float $x, float $y): void
    {
        Block::create([
            'stand_id' => $stand->id,
            'seat_category_id' => $seatCategory->id,
            'code' => $code,
            'seating_type' => 'unreserved',
            'capacity' => 250,
            'geometry' => ['x' => $x, 'y' => round($y, 2), 'w' => 140, 'h' => self::GOAL_H],
        ]);
    }
}
