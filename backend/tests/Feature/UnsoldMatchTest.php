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
use Database\Seeders\AddUnsoldMatchesSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnsoldMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_adds_unsold_matches_with_allocations_and_prices(): void
    {
        $this->seed(DatabaseSeeder::class);

        $unsold = FootballMatch::whereJsonContains('attributes->unsold', true)->orderBy('kickoff_at')->get();
        $this->assertCount(2, $unsold);
        $this->assertSame(['第37節', '第38節'], $unsold->pluck('section_label')->all());
        $this->assertSame($unsold->last()->id, FootballMatch::orderByDesc('kickoff_at')->first()->id);
        $this->assertSame('2026-11-28 05:00:00', $unsold[0]->kickoff_at->format('Y-m-d H:i:s'));

        foreach ($unsold as $match) {
            $this->assertSame('on_sale', $match->status);
            $this->assertSame(Block::count(), MatchBlockAllocation::where('match_id', $match->id)->count());
            $this->assertSame(12, MatchPrice::where('match_id', $match->id)->count());
        }
    }

    public function test_add_unsold_matches_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [FootballMatch::count(), MatchBlockAllocation::count(), MatchPrice::count()];

        $this->seed(AddUnsoldMatchesSeeder::class);

        $this->assertSame($counts, [FootballMatch::count(), MatchBlockAllocation::count(), MatchPrice::count()]);
    }

    public function test_add_unsold_matches_seeder_adds_missing_matches_to_existing_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ids = FootballMatch::whereJsonContains('attributes->unsold', true)->pluck('id');
        MatchBlockAllocation::whereIn('match_id', $ids)->delete();
        MatchPrice::whereIn('match_id', $ids)->delete();
        FootballMatch::whereIn('id', $ids)->delete();
        $matches = FootballMatch::count();

        $this->seed(AddUnsoldMatchesSeeder::class);
        $this->seed(AddUnsoldMatchesSeeder::class);

        $this->assertSame($matches + 2, FootballMatch::count());
        $new = FootballMatch::whereJsonContains('attributes->unsold', true)->get();
        $this->assertCount(2, $new);
        foreach ($new as $match) {
            $this->assertSame(Block::count(), MatchBlockAllocation::where('match_id', $match->id)->count());
            $this->assertSame(12, MatchPrice::where('match_id', $match->id)->count());
        }
    }

    public function test_generate_demo_data_skips_only_unsold_matches(): void
    {
        $this->seed(DatabaseSeeder::class);
        $command = app(GenerateDemoData::class);

        foreach (FootballMatch::all() as $match) {
            $expected = in_array($match->section_label, ['第37節', '第38節'], true);
            $this->assertSame($expected, $command->isUnsold($match), $match->section_label);
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
