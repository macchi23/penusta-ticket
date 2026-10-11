<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Models\Order;
use App\Models\SalePhase;
use App\Models\SeasonPassSeat;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Purchase\SalePhaseTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class SalePhaseSchemaTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSaleFixture();
        $this->buildSalePricesAndUser();
    }

    private function phase(string $kind, string $start, string $end, ?string $eligibility = null): SalePhase
    {
        return SalePhase::create([
            'match_id' => $this->match->id, 'kind' => $kind, 'eligibility' => $eligibility,
            'starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($end, 'UTC'),
        ]);
    }

    /** @param  callable():mixed  $fn */
    private function assertDbRejects(callable $fn, string $exception = QueryException::class): void
    {
        try {
            DB::transaction($fn);
        } catch (QueryException $e) {
            $this->assertInstanceOf($exception, $e);

            return;
        }
        $this->fail('制約違反になるはずが通った');
    }

    public function test_overlapping_phases_of_a_match_are_rejected_but_adjacent_ones_pass(): void
    {
        $this->match->salePhases()->delete();
        $this->phase('fanclub_presale', '2030-01-01 00:00:00', '2030-01-08 00:00:00', 'fanclub');
        $this->phase('general', '2030-01-08 00:00:00', '2030-02-01 00:00:00');
        $this->assertSame(2, $this->match->salePhases()->count());

        $this->match->salePhases()->delete();
        $this->phase('fanclub_presale', '2030-01-01 00:00:00', '2030-01-08 00:00:00', 'fanclub');
        $this->assertDbRejects(fn () => $this->phase('general', '2030-01-07 23:59:59', '2030-02-01 00:00:00'));
    }

    public function test_period_must_be_ascending(): void
    {
        $this->match->salePhases()->delete();
        $this->assertDbRejects(fn () => $this->phase('general', '2030-02-01 00:00:00', '2030-02-01 00:00:00'));
        $this->assertDbRejects(fn () => $this->phase('general', '2030-02-02 00:00:00', '2030-02-01 00:00:00'));
    }

    public function test_kind_is_unique_per_match(): void
    {
        $this->match->salePhases()->delete();
        $this->phase('general', '2030-01-01 00:00:00', '2030-01-02 00:00:00');
        $this->assertDbRejects(
            fn () => $this->phase('general', '2030-01-05 00:00:00', '2030-01-06 00:00:00'),
            UniqueConstraintViolationException::class,
        );
    }

    public function test_kind_and_eligibility_are_restricted(): void
    {
        $this->match->salePhases()->delete();
        $row = ['match_id' => $this->match->id, 'kind' => 'general', 'starts_at' => '2030-01-01 00:00:00',
            'ends_at' => '2030-01-02 00:00:00', 'created_at' => now(), 'updated_at' => now()];
        $this->assertDbRejects(fn () => DB::table('sale_phases')->insert(array_merge($row, ['kind' => 'lottery'])));
        $this->assertDbRejects(fn () => DB::table('sale_phases')->insert(array_merge($row, ['eligibility' => 'vip'])));
        $this->phase('general', '2030-01-01 00:00:00', '2030-01-02 00:00:00', 'season_pass');
        $this->assertSame(1, $this->match->salePhases()->count());
    }

    public function test_fixture_default_is_general_sale_now(): void
    {
        $now = CarbonImmutable::now('UTC');
        $general = $this->match->salePhases()->where('kind', 'general')->firstOrFail();
        $this->assertTrue($general->starts_at <= $now && $now < $general->ends_at);

        $this->makeSalePhases('presale');
        $presale = $this->match->salePhases()->first();
        $this->assertSame('fanclub_presale', $presale->kind->value);
        $this->assertTrue($presale->starts_at <= $now && $now < $presale->ends_at);

        $this->makeSalePhases('before');
        $this->assertTrue($this->match->salePhases()->first()->starts_at > $now);
    }

    public function test_template_yields_the_expected_phases(): void
    {
        $kickoff = CarbonImmutable::parse('2030-03-01 10:00:00', 'UTC');
        [$presale, $general] = SalePhaseTemplate::forKickoff($kickoff);

        $this->assertSame('fanclub_presale', $presale['kind']);
        $this->assertSame('fanclub', $presale['eligibility']);
        $this->assertTrue($presale['starts_at']->equalTo($kickoff->subDays(42)));
        $this->assertTrue($presale['ends_at']->equalTo($kickoff->subDays(35)));
        $this->assertSame('general', $general['kind']);
        $this->assertNull($general['eligibility']);
        $this->assertTrue($general['starts_at']->equalTo($presale['ends_at']));
        $this->assertTrue($general['ends_at']->equalTo($kickoff));
    }

    /** マイグレーション本体のバックフィル SQL をそのまま実行し、SalePhaseTemplate と同じ行になることを確かめる */
    public function test_migration_backfill_sql_matches_the_template(): void
    {
        $source = file_get_contents(database_path('migrations/2025_06_01_000010_create_sale_phases_table.php'));
        preg_match_all("/<<<'SQL'\n(.*?)\n\s*SQL\)/s", $source, $m);
        $this->assertCount(2, $m[1]);

        $this->match->salePhases()->delete();
        foreach ($m[1] as $sql) {
            DB::statement($sql);
        }

        $actual = $this->match->salePhases()->get();
        $expected = SalePhaseTemplate::forKickoff(CarbonImmutable::instance($this->match->kickoff_at));
        $this->assertCount(count($expected), $actual);
        foreach ($expected as $i => $row) {
            $this->assertSame($row['kind'], $actual[$i]->kind->value);
            $this->assertSame($row['method'], $actual[$i]->method);
            $this->assertSame($row['eligibility'], $actual[$i]->eligibility);
            $this->assertTrue($row['starts_at']->equalTo($actual[$i]->starts_at));
            $this->assertTrue($row['ends_at']->equalTo($actual[$i]->ends_at));
        }
    }

    public function test_season_pass_seats_are_unique_per_user_and_per_seat(): void
    {
        $block = $this->makeBlock('R1', 'reserved', 2);
        $seat1 = Seat::create(['block_id' => $block->id, 'row_label' => '1', 'number' => 1]);
        $seat2 = Seat::create(['block_id' => $block->id, 'row_label' => '1', 'number' => 2]);
        $bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
        $attrs = ['season_id' => $this->match->season_id, 'block_id' => $block->id];

        SeasonPassSeat::create($attrs + ['user_id' => $this->alice->id, 'seat_id' => $seat1->id]);

        $this->assertDbRejects(
            fn () => SeasonPassSeat::create($attrs + ['user_id' => $this->alice->id, 'seat_id' => $seat2->id]),
            UniqueConstraintViolationException::class,
        );
        $this->assertDbRejects(
            fn () => SeasonPassSeat::create($attrs + ['user_id' => $bob->id, 'seat_id' => $seat1->id]),
            UniqueConstraintViolationException::class,
        );

        // 自由席の枠（seat_id null）は座席の重複判定の対象外
        $free = $this->makeBlock('F1', 'free', 10);
        $freeAttrs = ['season_id' => $this->match->season_id, 'block_id' => $free->id, 'seat_id' => null];
        $carol = User::create(['name' => 'Carol', 'email' => 'c@example.com', 'password' => 'x']);
        SeasonPassSeat::create($freeAttrs + ['user_id' => $bob->id]);
        SeasonPassSeat::create($freeAttrs + ['user_id' => $carol->id]);
        $this->assertSame(3, SeasonPassSeat::count());
    }

    public function test_ticket_per_match_and_season_pass_seat_is_unique(): void
    {
        $block = $this->makeBlock('F1', 'free', 10);
        $sps = SeasonPassSeat::create([
            'season_id' => $this->match->season_id, 'user_id' => $this->alice->id, 'block_id' => $block->id,
        ]);
        $make = fn (?int $spsId) => Ticket::create([
            'match_id' => $this->match->id, 'block_id' => $block->id, 'season_pass_seat_id' => $spsId,
            'status' => 'paid', 'qr_uuid' => (string) Str::uuid(),
        ]);

        $make($sps->id);
        $this->assertDbRejects(fn () => $make($sps->id), UniqueConstraintViolationException::class);
        $make(null);
        $make(null);
        $this->assertSame(1, $sps->tickets()->count());
        $this->assertTrue($sps->tickets()->first()->seasonPassSeat->is($sps));
    }

    public function test_order_kind_defaults_to_purchase_and_is_restricted(): void
    {
        $attrs = ['user_id' => $this->alice->id, 'match_id' => $this->match->id, 'status' => 'pending', 'total' => 0, 'channel' => 'web'];
        $order = Order::create($attrs);
        $this->assertSame(OrderKind::Purchase, $order->fresh()->kind);

        $phase = $this->match->salePhases()->first();
        $order = Order::create(array_merge($attrs, ['status' => 'paid', 'kind' => 'season_pass', 'sale_phase_id' => $phase->id]));
        $this->assertTrue($order->salePhase->is($phase));

        $this->assertDbRejects(fn () => DB::table('orders')->insert(array_merge($attrs, ['status' => 'paid', 'kind' => 'gift', 'created_at' => now(), 'updated_at' => now()])));

        $phase->delete();
        $this->assertNull($order->fresh()->sale_phase_id);
    }
}
