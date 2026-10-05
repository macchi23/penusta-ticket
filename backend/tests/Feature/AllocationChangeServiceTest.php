<?php

namespace Tests\Feature;

use App\Enums\Allocation;
use App\Exceptions\AllocationChangeException;
use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchBlockAllocationChange;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Admin\AllocationChangeService;
use App\Services\Purchase\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\BuildsSaleFixture;
use Tests\Concerns\UsesProbeConnection;
use Tests\TestCase;

/**
 * 同時実行テストで別接続（probe）から見えるようコミットされる必要があるので、DatabaseTruncation を使う。
 * 終了時に全テーブルを空にして、後続の RefreshDatabase 系テストへデータを残さない。
 */
class AllocationChangeServiceTest extends TestCase
{
    use BuildsSaleFixture;
    use DatabaseTruncation;
    use UsesProbeConnection;

    private FootballMatch $match;

    private Block $reserved;

    private Block $free;

    private Block $reserved2;

    private TicketType $adult;

    private User $admin;

    private User $alice;

    /** @var array<int, Seat> */
    private array $seats = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));

        $this->buildSaleFixture();

        $this->reserved = $this->makeBlock('M1', 'reserved', 10);
        $this->free = $this->makeBlock('F1', 'unreserved', 5);
        $this->reserved2 = $this->makeBlock('M2', 'reserved', 10);

        foreach ([1, 2] as $n) {
            $this->seats[$n] = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => $n]);
        }
        $this->seats[3] = Seat::create(['block_id' => $this->reserved2->id, 'row_label' => 'A', 'number' => 1]);

        $this->buildSalePricesAndUser();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_applies_multiple_blocks_and_writes_audit_rows(): void
    {
        $result = $this->apply([
            $this->change($this->free, 'home', 'buffer'),
            $this->change($this->reserved, 'home', 'closed'),
        ]);

        $this->assertSame('closed', $this->current($this->reserved));
        $this->assertSame('buffer', $this->current($this->free));
        $this->assertSame('home', $this->current($this->reserved2));

        $rows = MatchBlockAllocationChange::orderBy('block_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$result->changeSetId], $rows->pluck('change_set_id')->unique()->all());
        $this->assertSame([$this->admin->id], $rows->pluck('changed_by')->unique()->all());
        $this->assertSame([Allocation::Home], $rows->pluck('from_allocation')->unique()->all());
        $this->assertSame([Allocation::Closed, Allocation::Buffer], $rows->pluck('to_allocation')->all());
        $this->assertTrue($rows[0]->changed_at->equalTo($result->changedAt));
        $this->assertSame(
            [
                ['block_id' => $this->reserved->id, 'from' => Allocation::Home, 'to' => Allocation::Closed],
                ['block_id' => $this->free->id, 'from' => Allocation::Home, 'to' => Allocation::Buffer],
            ],
            $result->changed,
        );
    }

    public function test_non_admin_is_rejected(): void
    {
        try {
            app(AllocationChangeService::class)->apply($this->alice, $this->match, [$this->change($this->reserved, 'home', 'buffer')]);
            $this->fail('AuthorizationException になるはずが成功した');
        } catch (AuthorizationException) {
            $this->assertSame('home', $this->current($this->reserved));
            $this->assertSame(0, MatchBlockAllocationChange::count());
        }
    }

    public function test_call_inside_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => $this->apply([$this->change($this->reserved, 'home', 'buffer')]));
    }

    public function test_non_positive_lock_timeout_falls_back_to_default(): void
    {
        foreach ([0, -5] as $value) {
            config(['ticketing.admin_lock_timeout_ms' => $value]);
            $statements = [];
            DB::listen(function ($q) use (&$statements) {
                $statements[] = strtolower($q->sql);
            });

            $this->apply([$this->change($this->reserved, 'home', 'buffer')]);
            $this->apply([$this->change($this->reserved, 'buffer', 'home')]);

            $this->assertContains("set local lock_timeout = '3000ms'", $statements);
        }
    }

    public function test_stale_expected_is_rejected_without_writes(): void
    {
        $e = $this->conflict([$this->change($this->reserved, 'away', 'closed')]);

        $this->assertSame('allocation_conflict', $e->errorCode);
        $this->assertSame([[
            'block_id' => $this->reserved->id, 'reason' => 'stale', 'current' => 'home', 'tickets' => ['paid' => 0, 'held' => 0],
        ]], $e->conflicts);
        $this->assertSame('home', $this->current($this->reserved));
        $this->assertSame(0, MatchBlockAllocationChange::count());
    }

    public function test_paid_and_live_held_tickets_block_change(): void
    {
        $this->makeTicket($this->reserved, $this->seats[1], 'paid');
        $this->makeTicket($this->free, null, 'held', now()->addMinutes(5));

        $e = $this->conflict([
            $this->change($this->reserved, 'home', 'buffer'),
            $this->change($this->free, 'home', 'buffer'),
        ]);

        $this->assertSame(
            [
                ['block_id' => $this->reserved->id, 'reason' => 'has_tickets', 'current' => 'home', 'tickets' => ['paid' => 1, 'held' => 0]],
                ['block_id' => $this->free->id, 'reason' => 'has_tickets', 'current' => 'home', 'tickets' => ['paid' => 0, 'held' => 1]],
            ],
            $e->conflicts,
        );
    }

    public function test_expired_held_released_and_expired_tickets_do_not_block(): void
    {
        $this->makeTicket($this->reserved, $this->seats[1], 'held', now()->subMinute());
        $this->makeTicket($this->reserved, $this->seats[2], 'released');
        $this->makeTicket($this->free, null, 'expired', now()->subMinute());

        $this->apply([
            $this->change($this->reserved, 'home', 'buffer'),
            $this->change($this->free, 'home', 'buffer'),
        ]);

        $this->assertSame('buffer', $this->current($this->reserved));
        $this->assertSame('buffer', $this->current($this->free));
    }

    public function test_mixed_ok_and_ng_blocks_apply_nothing(): void
    {
        $this->makeTicket($this->free, null, 'paid');

        $e = $this->conflict([
            $this->change($this->reserved, 'home', 'buffer'),
            $this->change($this->free, 'home', 'buffer'),
            $this->change($this->reserved2, 'away', 'buffer'),
        ]);

        $this->assertSame(
            [$this->free->id => 'has_tickets', $this->reserved2->id => 'stale'],
            array_column($e->conflicts, 'reason', 'block_id'),
        );
        $this->assertSame(['home', 'home', 'home'], [
            $this->current($this->reserved), $this->current($this->free), $this->current($this->reserved2),
        ]);
        $this->assertSame(0, MatchBlockAllocationChange::count());
    }

    public function test_locked_after_kickoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-03-01 10:00:00', 'UTC'));

        try {
            $this->apply([$this->change($this->reserved, 'home', 'buffer')]);
            $this->fail('MatchLocked になるはずが成功した');
        } catch (AllocationChangeException $e) {
            $this->assertSame('match_locked', $e->errorCode);
        }
        $this->assertSame('home', $this->current($this->reserved));
        $this->assertSame(0, MatchBlockAllocationChange::count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $other = FootballMatch::create([
            'season_id' => $this->match->season_id, 'home_team_id' => $this->match->home_team_id,
            'away_team_id' => $this->match->away_team_id, 'stadium_id' => $this->match->stadium_id,
            'kickoff_at' => CarbonImmutable::parse('2030-04-01 10:00:00', 'UTC'), 'status' => 'on_sale', 'section_label' => 'B',
        ]);

        $cases = [
            'empty' => [],
            'duplicate' => [$this->change($this->reserved, 'home', 'buffer'), $this->change($this->reserved, 'home', 'closed')],
            'no-op' => [$this->change($this->reserved, 'home', 'home')],
            'bad value' => [$this->change($this->reserved, 'home', 'bogus')],
            'bad expected' => [$this->change($this->reserved, 'bogus', 'home')],
            'no row' => [$this->change($this->reserved, 'home', 'buffer')],
        ];

        foreach ($cases as $name => $changes) {
            try {
                $match = $name === 'no row' ? $other : $this->match;
                app(AllocationChangeService::class)->apply($this->admin, $match, $changes);
                $this->fail("{$name}: InvalidArgumentException になるはずが成功した");
            } catch (InvalidArgumentException) {
                $this->assertSame('home', $this->current($this->reserved), $name);
                $this->assertSame(0, MatchBlockAllocationChange::count(), $name);
            }
        }
    }

    public function test_check_constraints_reject_bad_allocation_values(): void
    {
        $this->assertCheckViolation(fn () => DB::table('match_block_allocations')->where('match_id', $this->match->id)->update(['allocation' => 'bogus']));
        $this->assertCheckViolation(fn () => DB::table('allocation_template_entries')->insert([
            'allocation_template_id' => DB::table('allocation_templates')->insertGetId(['stadium_id' => $this->match->stadium_id, 'name' => 't', 'created_at' => now(), 'updated_at' => now()]),
            'block_id' => $this->free->id, 'allocation' => 'bogus', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertCheckViolation(fn () => DB::table('match_block_allocation_changes')->insert([
            'match_id' => $this->match->id, 'block_id' => $this->free->id, 'from_allocation' => 'home', 'to_allocation' => 'bogus',
            'changed_by' => $this->admin->id, 'change_set_id' => (string) Str::uuid(), 'changed_at' => now(),
        ]));
    }

    public function test_ticket_counts_groups_by_block_and_status(): void
    {
        $this->makeTicket($this->reserved, $this->seats[1], 'paid');
        $this->makeTicket($this->reserved, $this->seats[2], 'held', now()->addMinute());
        $this->makeTicket($this->free, null, 'held', now()->addMinute());
        $this->makeTicket($this->free, null, 'held', now()->subMinute());

        $this->assertSame([
            $this->reserved->id => ['paid' => 1, 'held' => 1],
            $this->free->id => ['paid' => 0, 'held' => 1],
        ], app(AllocationChangeService::class)->ticketCounts($this->match, CarbonImmutable::now()));
    }

    public function test_changing_to_buffer_makes_add_item_not_on_sale_and_back_to_home_allows_it(): void
    {
        $this->apply([
            $this->change($this->reserved, 'home', 'buffer'),
            $this->change($this->free, 'home', 'buffer'),
        ]);
        $this->assertNotOnSale(fn () => $this->addSeat(1));
        $this->assertNotOnSale(fn () => $this->addFree());

        $this->apply([
            $this->change($this->reserved, 'buffer', 'home'),
            $this->change($this->free, 'buffer', 'home'),
        ]);
        $this->addSeat(1);
        $this->addFree();
        $this->assertSame(2, Ticket::count());
    }

    public function test_probe_share_lock_makes_apply_busy(): void
    {
        $this->probeLock($this->reserved, 'for share');
        $this->assertBusy([$this->change($this->reserved, 'home', 'buffer')]);
    }

    public function test_probe_update_lock_makes_apply_busy(): void
    {
        $this->probeLock($this->free, 'for update');
        $this->assertBusy([$this->change($this->free, 'home', 'buffer')]);
    }

    public function test_ticket_committed_just_before_lock_is_detected(): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(function ($query) use (&$fired) {
            $sql = strtolower($query);
            if ($fired || ! str_contains($sql, 'match_block_allocations') || ! str_contains($sql, 'for update')) {
                return;
            }
            $fired = true;
            $this->probeConnection()->table('tickets')->insert([
                'match_id' => $this->match->id, 'block_id' => $this->reserved->id, 'seat_id' => $this->seats[1]->id,
                'status' => 'paid', 'qr_uuid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $e = $this->conflict([$this->change($this->reserved, 'home', 'buffer')]);

        $this->assertTrue($fired);
        $this->assertSame('has_tickets', $e->conflicts[0]['reason']);
        $this->assertSame('home', $this->current($this->reserved));
    }

    public function test_sql_order(): void
    {
        config(['ticketing.admin_lock_timeout_ms' => 3000]);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = ['sql' => strtolower($q->sql), 'bindings' => $q->bindings];
        });

        $this->apply([
            $this->change($this->reserved2, 'home', 'buffer'),
            $this->change($this->reserved, 'home', 'buffer'),
        ]);

        $sqls = array_column($queries, 'sql');
        $find = fn (string $pattern, int $from = 0) => collect($sqls)->keys()->first(fn ($i) => $i >= $from && preg_match($pattern, $sqls[$i]));

        $timeout = $find('/^set local lock_timeout = \'3000ms\'/');
        $lock = $find('/^select .* from "match_block_allocations" .*order by "block_id" asc for update/', (int) $timeout);
        $tickets = $find('/^select .* from "tickets"/', (int) $lock);
        $update = $find('/^update "match_block_allocations"/', (int) $tickets);
        $insert = $find('/^insert into "match_block_allocation_changes"/', (int) $update);

        foreach ([$timeout, $lock, $tickets, $update, $insert] as $i) {
            $this->assertNotNull($i);
        }
        $this->assertTrue($timeout < $lock && $lock < $tickets && $tickets < $update && $update < $insert);
        $this->assertDoesNotMatchRegularExpression('/for (update|share)/', $sqls[$tickets]);
        $updates = array_values(array_filter($queries, fn ($q) => str_starts_with($q['sql'], 'update "match_block_allocations"')));
        $this->assertCount(2, $updates);
    }

    private function makeTicket(Block $block, ?Seat $seat, string $status, ?\DateTimeInterface $expiresAt = null): Ticket
    {
        return Ticket::create([
            'match_id' => $this->match->id, 'block_id' => $block->id, 'seat_id' => $seat?->id,
            'status' => $status, 'qr_uuid' => (string) Str::uuid(), 'expires_at' => $expiresAt,
        ]);
    }

    private function change(Block $block, string $expected, string $allocation): array
    {
        return ['block_id' => $block->id, 'expected' => $expected, 'allocation' => $allocation];
    }

    private function apply(array $changes)
    {
        return app(AllocationChangeService::class)->apply($this->admin, $this->match, $changes);
    }

    private function conflict(array $changes): AllocationChangeException
    {
        try {
            $this->apply($changes);
        } catch (AllocationChangeException $e) {
            return $e;
        }
        $this->fail('AllocationConflict になるはずが成功した');
    }

    private function current(Block $block): string
    {
        return MatchBlockAllocation::where('match_id', $this->match->id)->where('block_id', $block->id)->value('allocation');
    }

    private function addSeat(int $n)
    {
        return app(CartService::class)->addItem($this->alice, $this->match, $this->seats[$n]->id, null, $this->adult->id);
    }

    private function addFree()
    {
        return app(CartService::class)->addItem($this->alice, $this->match, null, $this->free->id, $this->adult->id);
    }

    private function assertNotOnSale(callable $call): void
    {
        try {
            $call();
        } catch (PurchaseException $e) {
            $this->assertSame('not_on_sale', $e->errorCode);

            return;
        }
        $this->fail('notOnSale になるはずが成功した');
    }

    private function assertCheckViolation(callable $call): void
    {
        try {
            DB::transaction($call);
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());

            return;
        }
        $this->fail('check 制約違反になるはずが成功した');
    }

    private function assertBusy(array $changes): void
    {
        $expected = array_column($changes, 'expected', 'block_id');
        config(['ticketing.admin_lock_timeout_ms' => 200]);
        try {
            $this->apply($changes);
        } catch (AllocationChangeException $e) {
            $this->assertSame('allocation_busy', $e->errorCode);
            foreach ($expected as $blockId => $allocation) {
                $this->assertSame($allocation, MatchBlockAllocation::where('match_id', $this->match->id)->where('block_id', $blockId)->value('allocation'));
            }
            $this->assertSame(0, MatchBlockAllocationChange::count());

            return;
        }
        $this->fail('AllocationBusy になるはずが成功した');
    }
}
