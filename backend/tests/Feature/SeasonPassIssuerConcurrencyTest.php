<?php

namespace Tests\Feature;

use App\Exceptions\AllocationChangeException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\SeasonPassSeat;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Admin\AllocationChangeService;
use App\Services\SeasonPass\SeasonPassIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSaleFixture;
use Tests\Concerns\UsesProbeConnection;
use Tests\TestCase;

/**
 * 別接続（probe）から見えるようコミットが要るので DatabaseTruncation。終了時に全テーブルを空にする。
 */
class SeasonPassIssuerConcurrencyTest extends TestCase
{
    use BuildsSaleFixture;
    use DatabaseTruncation;
    use UsesProbeConnection;

    private FootballMatch $match;

    private Block $reserved;

    private Block $free;

    private TicketType $adult;

    private TicketType $pass;

    private User $alice;

    private Seat $seat;

    private SeasonPassSeat $fixed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));

        $this->buildSaleFixture();
        $this->reserved = $this->makeBlock('M1', 'reserved', 10);
        $this->free = $this->makeBlock('F1', 'unreserved', 5);
        $this->seat = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => 1]);
        $this->buildSalePricesAndUser();
        $this->pass = TicketType::create(['name' => '年パス', 'code' => 'season_pass', 'display_order' => 3]);
        $this->fixed = SeasonPassSeat::create([
            'season_id' => $this->match->season_id, 'user_id' => $this->alice->id,
            'block_id' => $this->reserved->id, 'seat_id' => $this->seat->id,
        ]);
    }

    public function test_issue_waits_on_reserved_purchase_share_lock(): void
    {
        $this->probeLock($this->reserved, 'for share');
        DB::statement("SET lock_timeout = '200ms'");

        try {
            $this->issue();
            $this->fail('待たされるはずが成功した');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());
        }
    }

    public function test_issue_waits_on_unreserved_lock(): void
    {
        SeasonPassSeat::create([
            'season_id' => $this->match->season_id, 'user_id' => User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x'])->id,
            'block_id' => $this->free->id, 'seat_id' => null,
        ]);
        $this->probeLock($this->free, 'for update');
        DB::statement("SET lock_timeout = '200ms'");

        $this->expectException(QueryException::class);
        $this->issue();
    }

    public function test_ticket_issued_by_another_session_just_before_lock_is_not_duplicated(): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(function ($query) use (&$fired) {
            $sql = strtolower($query);
            if ($fired || ! str_contains($sql, 'match_block_allocations') || ! str_contains($sql, 'for update')) {
                return;
            }
            $fired = true;
            $probe = $this->probeConnection();
            $orderId = $probe->table('orders')->insertGetId([
                'user_id' => $this->alice->id, 'match_id' => $this->match->id, 'status' => 'paid', 'kind' => 'season_pass',
                'total' => 0, 'channel' => 'system', 'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ticketId = $probe->table('tickets')->insertGetId([
                'match_id' => $this->match->id, 'block_id' => $this->reserved->id, 'seat_id' => $this->seat->id,
                'season_pass_seat_id' => $this->fixed->id, 'status' => 'paid', 'qr_uuid' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $probe->table('order_items')->insert([
                'order_id' => $orderId, 'ticket_id' => $ticketId, 'ticket_type_id' => $this->pass->id, 'price' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertSame(0, $this->issue());
        $this->assertTrue($fired);
        $this->assertSame(1, Ticket::count());
    }

    public function test_issued_seat_blocks_allocation_change(): void
    {
        $this->assertSame(1, $this->issue());

        $admin = User::factory()->admin()->create();
        try {
            app(AllocationChangeService::class)->apply($admin, $this->match, [
                ['block_id' => $this->reserved->id, 'expected' => 'home', 'allocation' => 'buffer'],
            ]);
            $this->fail('has_tickets で拒否されるはずが成功した');
        } catch (AllocationChangeException $e) {
            $this->assertSame('has_tickets', $e->conflicts[0]['reason']);
        }
    }

    private function issue(): int
    {
        return app(SeasonPassIssuer::class)->issueForMatch($this->match);
    }
}
