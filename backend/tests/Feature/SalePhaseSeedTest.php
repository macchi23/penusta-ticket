<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\SalePhase;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalePhaseSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_matches_all_get_two_sale_phases(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, FootballMatch::count());
        $counts = SalePhase::selectRaw('match_id, count(*) as n')->groupBy('match_id')->pluck('n', 'match_id');
        $this->assertSame(FootballMatch::count(), $counts->count());
        $this->assertSame([2], $counts->unique()->values()->all());
    }
}
