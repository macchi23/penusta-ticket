<?php

namespace Tests\Feature;

use App\Exceptions\PurchaseException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchPrice;
use App\Models\Order;
use App\Models\Season;
use App\Models\Seat;
use App\Models\SeatCategory;
use App\Models\Stadium;
use App\Models\Stand;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Purchase\AllocationLocks;
use App\Services\Purchase\CartService;
use App\Services\Purchase\CheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 購入側の allocation 行ロックを、別接続（probe）が握った行との競合で検証する。
 * probe から見えるようコミットされる必要があるので、RefreshDatabase ではなく DatabaseTruncation を使う。
 * 終了時に全テーブルを空にして、後続の RefreshDatabase 系テストへデータを残さない。
 */
class AllocationLockTest extends TestCase
{
    use DatabaseTruncation;

    private FootballMatch $match;

    private Block $reserved;

    private Block $reserved2;

    private Block $free;

    private Block $free2;

    private TicketType $adult;

    private User $alice;

    /** @var array<int, Seat> */
    private array $seats = [];

    private ?Connection $probe = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));

        $stadium = Stadium::create(['name' => 'Test Stadium', 'short_name' => 'TS']);
        $stand = Stand::create(['stadium_id' => $stadium->id, 'name' => 'Main', 'code' => 'M']);
        $category = SeatCategory::create(['stadium_id' => $stadium->id, 'name' => 'S席', 'display_order' => 1]);
        $season = Season::create(['name' => 'Season 2026', 'year' => 2026]);
        $home = Team::create(['name' => 'H', 'short_name' => 'H', 'color' => '#000000', 'is_home_club' => true]);
        $away = Team::create(['name' => 'A', 'short_name' => 'A', 'color' => '#ffffff', 'is_home_club' => false]);

        $this->match = FootballMatch::create([
            'season_id' => $season->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'stadium_id' => $stadium->id, 'kickoff_at' => CarbonImmutable::parse('2030-03-01 10:00:00', 'UTC'),
            'status' => 'on_sale', 'section_label' => 'A',
        ]);

        // block_id の昇順が reserved, free, reserved2, free2 になるよう作る
        $this->reserved = $this->makeBlock($stand, $category, 'M1', 'reserved', 10);
        $this->free = $this->makeBlock($stand, $category, 'F1', 'unreserved', 5);
        $this->reserved2 = $this->makeBlock($stand, $category, 'M2', 'reserved', 10);
        $this->free2 = $this->makeBlock($stand, $category, 'F2', 'unreserved', 5);

        foreach ([1, 2] as $n) {
            $this->seats[$n] = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => $n]);
        }
        $this->seats[3] = Seat::create(['block_id' => $this->reserved2->id, 'row_label' => 'A', 'number' => 1]);

        $this->adult = TicketType::create(['name' => '大人', 'code' => 'adult', 'display_order' => 1]);
        MatchPrice::create(['match_id' => $this->match->id, 'seat_category_id' => $category->id,
            'ticket_type_id' => $this->adult->id, 'price' => 5000]);

        $this->alice = User::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => 'x']);
    }

    protected function tearDown(): void
    {
        if ($this->probe !== null) {
            if ($this->probe->transactionLevel() > 0) {
                $this->probe->rollBack();
            }
            DB::purge('probe');
            $this->probe = null;
        }
        // 後続の RefreshDatabase 系テストの前提（空のテーブル）を壊さない
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_admin_style_update_lock_blocks_reserved_add_item(): void
    {
        $this->probeLock($this->reserved, 'for update');
        $this->shortLockTimeout();

        $this->assertLockNotAvailable(fn () => $this->addSeat(1));
        $this->assertSame(0, Ticket::count());
    }

    public function test_admin_style_update_lock_blocks_reserved_checkout(): void
    {
        $order = $this->cartWith([$this->seats[1]]);
        $this->probeLock($this->reserved, 'for update');
        $this->shortLockTimeout();

        $this->assertLockNotAvailable(fn () => app(CheckoutService::class)->checkout($this->alice, $order->id));
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_share_lock_by_other_buyer_does_not_block_reserved_add_item(): void
    {
        $this->probeLock($this->reserved, 'for share');
        $this->shortLockTimeout();

        $change = $this->addSeat(2);

        $this->assertTrue($change->changed);
        $this->assertSame(1, Ticket::count());
    }

    public function test_share_lock_by_other_buyer_does_not_block_reserved_checkout(): void
    {
        $order = $this->cartWith([$this->seats[1]]);
        $this->probeLock($this->reserved, 'for share');
        $this->shortLockTimeout();

        $result = app(CheckoutService::class)->checkout($this->alice, $order->id);

        $this->assertSame('paid', $result->order->status->value);
    }

    public function test_update_lock_blocks_unreserved_add_item_and_set_quantities(): void
    {
        $this->probeLock($this->free, 'for update');
        $this->shortLockTimeout();

        $this->assertLockNotAvailable(fn () => app(CartService::class)
            ->addItem($this->alice, $this->match, null, $this->free->id, $this->adult->id));
        $this->assertLockNotAvailable(fn () => app(CartService::class)
            ->setUnreservedQuantities($this->alice, $this->match, $this->free, [
                ['ticket_type_id' => $this->adult->id, 'quantity' => 1],
            ]));
        $this->assertSame(0, Ticket::count());
    }

    public function test_share_lock_blocks_unreserved_add_item(): void
    {
        // 自由席は SHARE の他人とも並行させない（capacity カウントのため UPDATE を取る）
        $this->probeLock($this->free, 'for share');
        $this->shortLockTimeout();

        $this->assertLockNotAvailable(fn () => app(CartService::class)
            ->addItem($this->alice, $this->match, null, $this->free->id, $this->adult->id));
    }

    public function test_add_item_locks_allocation_before_creating_ticket(): void
    {
        $log = $this->captureSql();
        $this->addSeat(1);
        $this->assertLockBeforeTicketWrite($log(), 'for share');

        $log = $this->captureSql();
        app(CartService::class)->addItem($this->alice, $this->match, null, $this->free->id, $this->adult->id);
        $this->assertLockBeforeTicketWrite($log(), 'for update');
    }

    public function test_checkout_locks_allocations_in_ascending_block_order_before_tickets(): void
    {
        // 追加順はあえて block_id の降順にする
        $order = $this->cartWith([$this->seats[3]], [$this->free2, $this->free], [$this->seats[1]]);

        $log = $this->captureSql();
        app(CheckoutService::class)->checkout($this->alice, $order->id);
        $queries = $log();

        $locks = array_values(array_filter($queries, fn ($q) => $this->isAllocationLock($q)));
        $this->assertSame(
            [$this->reserved->id, $this->free->id, $this->reserved2->id, $this->free2->id],
            array_map(fn ($q) => (int) $q['bindings'][1], $locks),
        );
        $this->assertSame(
            ['for share', 'for update', 'for share', 'for update'],
            array_map(fn ($q) => $this->lockMode($q['sql']), $locks),
        );

        $this->assertLessThan($this->firstTicketAccess($queries), $this->indexOf($queries, $locks[3]));
    }

    public function test_set_quantities_locks_allocation_before_releasing_tickets(): void
    {
        $child = TicketType::create(['name' => '子供', 'code' => 'child', 'display_order' => 2]);
        MatchPrice::create(['match_id' => $this->match->id, 'seat_category_id' => $this->free->seat_category_id,
            'ticket_type_id' => $child->id, 'price' => 2500]);
        $service = app(CartService::class);
        $service->setUnreservedQuantities($this->alice, $this->match, $this->free, [
            ['ticket_type_id' => $this->adult->id, 'quantity' => 1],
        ]);

        // 大人を減らし、子供を増やす
        $log = $this->captureSql();
        $service->setUnreservedQuantities($this->alice, $this->match, $this->free, [
            ['ticket_type_id' => $this->adult->id, 'quantity' => 0],
            ['ticket_type_id' => $child->id, 'quantity' => 1],
        ]);
        $queries = $log();

        $this->assertLockBeforeTicketWrite($queries, 'for update');
        $this->assertSame(1, Ticket::where('status', 'released')->count());
    }

    public function test_checkout_does_not_lock_blocks_of_released_tickets(): void
    {
        $order = $this->cartWith([$this->seats[1]], [$this->free]);
        $this->cartWith([$this->seats[3]]);
        foreach ([$this->free->id, $this->reserved2->id] as $blockId) {
            $itemId = $order->items()->whereIn('ticket_id', Ticket::where('block_id', $blockId)->select('id'))->value('id');
            app(CartService::class)->removeItem($this->alice, $this->match, $itemId);
        }
        $this->probeLock($this->free, 'for update');
        $this->shortLockTimeout();
        $log = $this->captureSql();

        $result = app(CheckoutService::class)->checkout($this->alice, $order->id);

        $this->assertSame('paid', $result->order->status->value);
        $locks = array_values(array_filter($log(), fn ($q) => $this->isAllocationLock($q)));
        $this->assertSame([$this->reserved->id], array_map(fn ($q) => (int) $q['bindings'][1], $locks));
    }

    public function test_add_item_keeps_single_time_across_allocation_lock(): void
    {
        $this->addSeat(1);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:09:59', 'UTC'));

        // allocation のロック直後に、カートの期限を跨ぐ
        $fired = false;
        DB::listen(function ($query) use (&$fired) {
            if (! $fired && $this->isAllocationLock(['sql' => strtolower($query->sql)])) {
                $fired = true;
                $this->travelTo(CarbonImmutable::parse('2026-06-01 00:10:01', 'UTC'));
            }
        });
        $this->addSeat(2);

        $this->assertTrue($fired);
        $this->assertSame(1, Ticket::distinct()->count('expires_at'));
    }

    public function test_add_item_decides_by_locked_allocation_value(): void
    {
        // allocation のロックを取る直前に、別トランザクションが buffer をコミットした状況
        $this->commitBufferBeforeLock($this->reserved, 'for share');

        $this->assertNotOnSale(fn () => $this->addSeat(1));
        $this->assertSame(0, Ticket::count());
    }

    public function test_set_quantities_decides_by_locked_allocation_value(): void
    {
        // 先頭のロック無し読み取りは home を見る。最終判定は holdMany のロック済みの値で行う
        $this->commitBufferBeforeLock($this->free, 'for update');

        $this->assertNotOnSale(fn () => app(CartService::class)
            ->setUnreservedQuantities($this->alice, $this->match, $this->free, [
                ['ticket_type_id' => $this->adult->id, 'quantity' => 1],
            ]));
        $this->assertSame(0, Ticket::where('block_id', $this->free->id)->count());
    }

    public function test_checkout_decides_by_locked_allocation_value(): void
    {
        $order = $this->cartWith([$this->seats[1]]);
        $this->commitBufferBeforeLock($this->reserved, 'for share');

        $this->assertNotOnSale(fn () => app(CheckoutService::class)->checkout($this->alice, $order->id));
        $this->assertSame('held', Ticket::sole()->status->value);
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_locks_treat_missing_row_as_closed(): void
    {
        MatchBlockAllocation::where('block_id', $this->free->id)->delete();

        $map = app(AllocationLocks::class)->forPurchase($this->match->id, [$this->free2, $this->free]);

        $this->assertSame([$this->free->id => 'closed', $this->free2->id => 'home'], $map);
    }

    private function makeBlock(Stand $stand, SeatCategory $category, string $code, string $type, int $capacity): Block
    {
        $block = Block::create([
            'stand_id' => $stand->id, 'seat_category_id' => $category->id, 'code' => $code,
            'seating_type' => $type, 'capacity' => $capacity, 'geometry' => ['type' => 'rect'],
        ]);
        MatchBlockAllocation::create(['match_id' => $this->match->id, 'block_id' => $block->id, 'allocation' => 'home']);

        return $block;
    }

    private function addSeat(int $n)
    {
        return app(CartService::class)->addItem($this->alice, $this->match, $this->seats[$n]->id, null, $this->adult->id);
    }

    /**
     * @param  array<int, Seat>  $seats
     * @param  array<int, Block>  $freeBlocks
     * @param  array<int, Seat>  $moreSeats
     */
    private function cartWith(array $seats, array $freeBlocks = [], array $moreSeats = []): Order
    {
        foreach ($seats as $seat) {
            app(CartService::class)->addItem($this->alice, $this->match, $seat->id, null, $this->adult->id);
        }
        foreach ($freeBlocks as $block) {
            app(CartService::class)->addItem($this->alice, $this->match, null, $block->id, $this->adult->id);
        }
        foreach ($moreSeats as $seat) {
            app(CartService::class)->addItem($this->alice, $this->match, $seat->id, null, $this->adult->id);
        }

        return Order::firstOrFail();
    }

    private function probeConnection(): Connection
    {
        if ($this->probe === null) {
            config(['database.connections.probe' => config('database.connections.pgsql')]);
            $this->probe = DB::connection('probe');
        }

        return $this->probe;
    }

    /** 別接続でトランザクションを開き、allocation 行を指定モードで握ったままにする。 */
    private function probeLock(Block $block, string $mode): void
    {
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select(
            "select id from match_block_allocations where match_id = ? and block_id = ? {$mode}",
            [$this->match->id, $block->id],
        );
    }

    private function shortLockTimeout(): void
    {
        DB::statement("SET lock_timeout = '200ms'");
    }

    private function assertLockNotAvailable(callable $call): void
    {
        try {
            $call();
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());

            return;
        }
        $this->fail('ロック待ちでタイムアウトするはずが成功した');
    }

    private function assertNotOnSale(callable $call): void
    {
        try {
            $call();
        } catch (PurchaseException $e) {
            $this->assertSame(PurchaseException::notOnSale()->getMessage(), $e->getMessage());

            return;
        }
        $this->fail('notOnSale になるはずが成功した');
    }

    /**
     * 本接続が allocation のロッククエリを実行する直前に、別接続が buffer をコミットする
     * （ロック待ちの間に管理者の変更がコミットされた状況の再現）。
     */
    private function commitBufferBeforeLock(Block $block, string $mode): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(function ($query) use (&$fired, $block, $mode) {
            $sql = strtolower($query);
            if ($fired || ! $this->isAllocationLock(['sql' => $sql]) || $this->lockMode($sql) !== $mode) {
                return;
            }
            $fired = true;
            $this->probeConnection()->table('match_block_allocations')
                ->where('match_id', $this->match->id)->where('block_id', $block->id)
                ->update(['allocation' => 'buffer']);
        });
    }

    /** @return callable(): array<int, array{sql: string, bindings: array}> */
    private function captureSql(): callable
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        return function () use (&$queries) {
            $copy = $queries;
            $queries = [];

            return $copy;
        };
    }

    private function isAllocationLock(array $q): bool
    {
        return str_contains($q['sql'], 'match_block_allocations')
            && str_starts_with(ltrim($q['sql']), 'select')
            && $this->lockMode($q['sql']) !== null;
    }

    private function lockMode(string $sql): ?string
    {
        $sql = strtolower($sql);

        return str_contains($sql, 'for update') ? 'for update' : (str_contains($sql, 'for share') ? 'for share' : null);
    }

    private function indexOf(array $queries, array $q): int
    {
        return array_search($q, $queries, true);
    }

    /** tickets への書き込み、または tickets 行のロック（select ... for update）の最初の位置。 */
    private function firstTicketAccess(array $queries): int
    {
        foreach ($queries as $i => $q) {
            if (preg_match('/^(insert into|update) "tickets"/', $q['sql'])
                || preg_match('/^select .* from "tickets" .*for update/', $q['sql'])) {
                return $i;
            }
        }
        $this->fail('tickets への書き込み / ロックが無い');
    }

    private function assertLockBeforeTicketWrite(array $queries, string $mode): void
    {
        $lock = null;
        foreach ($queries as $i => $q) {
            if ($this->isAllocationLock($q)) {
                $lock ??= $i;
                $this->assertSame($mode, $this->lockMode($q['sql']));
            }
        }
        $this->assertNotNull($lock, 'allocation のロックが無い');
        $this->assertLessThan($this->firstTicketAccess($queries), $lock);
    }
}
