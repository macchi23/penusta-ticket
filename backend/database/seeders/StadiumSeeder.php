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
     * SVG viewbox is 1000x700. The pitch occupies x:250-750, y:210-490.
     */
    private const UNIT = 58;

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

        // メインスタンド: M1-M8 指定席（x250-750に等間隔配置）。中央のM4,M5はカテゴリー1、それ以外はカテゴリー2。
        $mainCentralCodes = ['M4', 'M5'];
        foreach ($this->distributeHorizontal(250, 750, 8) as $i => $x) {
            $code = 'M'.($i + 1);
            $this->createReservedBlock(
                stand: $mainStand,
                seatCategory: in_array($code, $mainCentralCodes, true) ? $category1 : $category2,
                code: $code,
                x: $x,
                y: 80,
            );
        }

        // バックスタンド: B1-B8 指定席（カテゴリー3）
        foreach ($this->distributeHorizontal(250, 750, 8) as $i => $x) {
            $this->createReservedBlock(
                stand: $backStand,
                seatCategory: $category3,
                code: 'B'.($i + 1),
                x: $x,
                y: 520,
            );
        }

        // 北スタンド（ホームゴール裏）: N1-N4 自由席（ゴール裏カテゴリー）
        foreach ($this->distributeVertical(210, 490, 4) as $i => $y) {
            $this->createUnreservedBlock(
                stand: $northStand,
                seatCategory: $goalBack,
                code: 'N'.($i + 1),
                x: 60,
                y: $y,
            );
        }

        // 南スタンド: S1-S4 自由席（ゴール裏カテゴリー、アウェー側になりうる）
        foreach ($this->distributeVertical(210, 490, 4) as $i => $y) {
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
    private function distributeHorizontal(float $start, float $end, int $count): array
    {
        $w = self::UNIT;
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
    private function distributeVertical(float $start, float $end, int $count): array
    {
        return $this->distributeHorizontal($start, $end, $count);
    }

    private function createReservedBlock(Stand $stand, SeatCategory $seatCategory, string $code, float $x, float $y): void
    {
        $block = Block::create([
            'stand_id' => $stand->id,
            'seat_category_id' => $seatCategory->id,
            'code' => $code,
            'seating_type' => 'reserved',
            'capacity' => 200,
            'geometry' => ['x' => round($x, 2), 'y' => $y, 'w' => self::UNIT, 'h' => 100],
        ]);

        $rows = range('A', 'J');
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
            'capacity' => 500,
            'geometry' => ['x' => $x, 'y' => round($y, 2), 'w' => 140, 'h' => self::UNIT],
        ]);
    }
}
