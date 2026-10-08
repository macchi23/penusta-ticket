<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDemoData;
use App\Models\ActivityEvent;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MatchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnsoldMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_marks_matches_after_the_selling_ones_as_unsold(): void
    {
        $this->seed(DatabaseSeeder::class);

        $onSale = FootballMatch::where('status', 'on_sale')->orderBy('kickoff_at')->get();
        $selling = $onSale->take(MatchSeeder::SELLING_MATCH_COUNT);
        $unsold = $onSale->skip(MatchSeeder::SELLING_MATCH_COUNT);

        $this->assertGreaterThan(0, $unsold->count());
        foreach ($selling as $match) {
            $this->assertArrayNotHasKey(MatchSeeder::UNSOLD_KEY, $match->attributes);
        }
        foreach ($unsold as $match) {
            $this->assertTrue($match->attributes[MatchSeeder::UNSOLD_KEY]);
        }
        $this->assertSame($unsold->count(), FootballMatch::whereJsonContains('attributes->unsold', true)->count());
        $this->assertSame($unsold->last()->id, FootballMatch::orderByDesc('kickoff_at')->first()->id);

        foreach ($unsold->take(3) as $match) {
            $this->assertSame(Block::count(), MatchBlockAllocation::where('match_id', $match->id)->count());
            $this->assertSame(12, MatchPrice::where('match_id', $match->id)->count());
        }
    }

    public function test_generate_demo_data_skips_only_unsold_matches(): void
    {
        $this->seed(DatabaseSeeder::class);
        $command = app(GenerateDemoData::class);

        foreach (FootballMatch::all() as $match) {
            $expected = ($match->attributes[MatchSeeder::UNSOLD_KEY] ?? false) === true;
            $this->assertSame($expected, $command->isUnsold($match), $match->season->name.' '.$match->section_label);
        }
    }

    public function test_generate_or_skip_creates_nothing_for_unsold_match(): void
    {
        $this->seed(DatabaseSeeder::class);
        $command = app(GenerateDemoData::class);
        $unsold = FootballMatch::whereJsonContains('attributes->unsold', true)->first();

        // ユーザー等の事前準備は無い。スキップせず生成に入ればここで失敗する
        $command->generateOrSkip(
            $unsold,
            Block::with('seatCategory', 'stand')->get()->keyBy('id'),
            Seat::select('id', 'block_id')->get()->groupBy('block_id'),
            DB::table('match_block_allocations')->get()->groupBy('match_id'),
            [],
            TicketType::pluck('id', 'code'),
        );

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, ActivityEvent::count());
    }
}
