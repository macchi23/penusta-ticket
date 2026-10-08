<?php

namespace Tests\Feature;

use App\Models\AllocationTemplate;
use App\Models\Block;
use App\Models\Seat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StadiumSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_stadium_has_20000_capacity_in_56_blocks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(56, Block::count());
        $this->assertSame(20000, (int) Block::sum('capacity'));

        $byStand = Block::with('stand')->get()->groupBy(fn ($b) => $b->stand->code);
        $this->assertSame(12000, $byStand['MAIN']->sum('capacity') + $byStand['BACK']->sum('capacity'));
        $this->assertSame(8000, $byStand['NORTH']->sum('capacity') + $byStand['SOUTH']->sum('capacity'));
        $this->assertCount(12, $byStand['MAIN']);
        $this->assertCount(12, $byStand['BACK']);
        $this->assertCount(16, $byStand['NORTH']);
        $this->assertCount(16, $byStand['SOUTH']);
    }

    public function test_reserved_blocks_have_matching_seats_and_unreserved_have_none(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expectedRows = range('A', 'Y');
        foreach (Block::where('seating_type', 'reserved')->get() as $block) {
            $this->assertSame(500, $block->capacity);
            $this->assertSame(500, Seat::where('block_id', $block->id)->count(), $block->code);
            $this->assertSame($expectedRows, Seat::where('block_id', $block->id)->distinct()->orderBy('row_label')->pluck('row_label')->all());
            $this->assertSame(1, (int) Seat::where('block_id', $block->id)->min('number'));
            $this->assertSame(20, (int) Seat::where('block_id', $block->id)->max('number'));
        }
        $this->assertSame(24, Block::where('seating_type', 'reserved')->count());

        $unreserved = Block::where('seating_type', 'unreserved')->get();
        $this->assertCount(32, $unreserved);
        foreach ($unreserved as $block) {
            $this->assertSame(250, $block->capacity);
            $this->assertSame(0, Seat::where('block_id', $block->id)->count(), $block->code);
        }
    }

    public function test_seat_categories_by_block(): void
    {
        $this->seed(DatabaseSeeder::class);

        // カテゴリー1: M5-M8
        $cat1 = Block::with('seatCategory')->where('code', 'like', 'M%')->get()
            ->filter(fn ($b) => $b->seatCategory->name === 'カテゴリー1')->pluck('code')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['M5', 'M6', 'M7', 'M8'], $cat1);

        // カテゴリー2: M1-M4, M9-M12
        $cat2 = Block::with('seatCategory')->where('code', 'like', 'M%')->get()
            ->filter(fn ($b) => $b->seatCategory->name === 'カテゴリー2')->pluck('code')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['M1', 'M2', 'M3', 'M4', 'M9', 'M10', 'M11', 'M12'], $cat2);

        // カテゴリー3: B1-B12
        $cat3 = Block::with('seatCategory')->get()
            ->filter(fn ($b) => $b->seatCategory->name === 'カテゴリー3')->pluck('code')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9', 'B10', 'B11', 'B12'], $cat3);

        // ゴール裏: N1-N16, S1-S16
        $goalBack = Block::with('seatCategory')->get()
            ->filter(fn ($b) => $b->seatCategory->name === 'ゴール裏')->pluck('code')->sort()->values()->all();
        $expected = array_merge(
            array_map(fn ($i) => 'N'.$i, range(1, 16)),
            array_map(fn ($i) => 'S'.$i, range(1, 16))
        );
        $this->assertEqualsCanonicalizing($expected, $goalBack);
    }

    public function test_block_geometry_fits_viewbox_without_overlap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rects = Block::all()->map(fn ($b) => [$b->code, ...array_map('floatval', [
            $b->geometry['x'], $b->geometry['y'], $b->geometry['w'], $b->geometry['h'],
        ])])->all();

        foreach ($rects as [$code, $x, $y, $w, $h]) {
            $this->assertGreaterThanOrEqual(0, $x, $code);
            $this->assertGreaterThanOrEqual(0, $y, $code);
            $this->assertLessThanOrEqual(1000, $x + $w, $code);
            $this->assertLessThanOrEqual(700, $y + $h, $code);
        }

        $rects[] = ['pitch', 250.0, 210.0, 500.0, 280.0];
        for ($i = 0; $i < count($rects); $i++) {
            for ($j = $i + 1; $j < count($rects); $j++) {
                [$ca, $ax, $ay, $aw, $ah] = $rects[$i];
                [$cb, $bx, $by, $bw, $bh] = $rects[$j];
                $overlap = $ax < $bx + $bw && $bx < $ax + $aw && $ay < $by + $bh && $by < $ay + $ah;
                $this->assertFalse($overlap, "{$ca} overlaps {$cb}");
            }
        }
    }

    public function test_allocation_templates_mark_away_and_buffer_blocks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTemplate('通常配置', ['S1', 'S2', 'S3', 'S4'], ['S5']);
        $this->assertTemplate('大規模アウェー配置', ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8'], ['S9']);
    }

    /**
     * @param  array<int, string>  $away
     * @param  array<int, string>  $buffer
     */
    private function assertTemplate(string $name, array $away, array $buffer): void
    {
        $template = AllocationTemplate::where('name', $name)->firstOrFail();
        $codes = fn (string $allocation) => $template->entries()->where('allocation', $allocation)
            ->join('blocks', 'blocks.id', '=', 'allocation_template_entries.block_id')
            ->pluck('blocks.code')->sort()->values()->all();

        $this->assertSame(collect($away)->sort()->values()->all(), $codes('away'));
        $this->assertSame($buffer, $codes('buffer'));
        $this->assertSame(56, $template->entries()->count());
    }
}
