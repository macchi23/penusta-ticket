<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ResaleException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleListing;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSaleFixture;
use Tests\Concerns\UsesProbeConnection;
use Tests\TestCase;

/**
 * 別接続（probe）から見えるようコミットが要るので DatabaseTruncation。終了時に全テーブルを空にする。
 * 「同時」は、本接続がロックを取る直前に probe 側で別の操作をコミットして作る。
 */
class ResaleServiceConcurrencyTest extends TestCase
{
    use BuildsSaleFixture;
    use DatabaseTruncation;
    use UsesProbeConnection;

    private FootballMatch $match;

    private Block $reserved;

    private TicketType $adult;

    private User $alice;

    private User $bob;

    private User $carol;

    private int $seatNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-02-01 00:00:00', 'UTC'));
        config(['ticketing.resale.lock_timeout_ms' => 200]);

        $this->buildSaleFixture();
        $this->reserved = $this->makeBlock('M1', 'reserved', 20);
        $this->buildSalePricesAndUser();
        $this->bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
        $this->carol = User::create(['name' => 'Carol', 'email' => 'c@example.com', 'password' => 'x']);
    }

    private function service(): ResaleService
    {
        return app(ResaleService::class);
    }

    private function listing(?User $seller = null): ResaleListing
    {
        $seller ??= $this->alice;
        $seat = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => ++$this->seatNo]);
        $order = Order::create([
            'user_id' => $seller->id, 'match_id' => $this->match->id, 'status' => OrderStatus::Paid, 'kind' => OrderKind::Purchase,
            'total' => 5000, 'channel' => 'web', 'ordered_at' => now(),
        ]);
        $ticket = Ticket::create([
            'match_id' => $this->match->id, 'block_id' => $this->reserved->id, 'seat_id' => $seat->id,
            'status' => TicketStatus::Paid, 'qr_uuid' => (string) Str::uuid(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'ticket_id' => $ticket->id, 'ticket_type_id' => $this->adult->id, 'price' => 5000]);

        return $this->service()->list($seller, $ticket, 4000);
    }

    /** 本接続が対象テーブルの FOR UPDATE を発行する直前に、一度だけ $competing を実行する。 */
    private function beforeLock(string $table, Closure $competing): void
    {
        $fired = false;
        DB::connection()->beforeExecuting(function ($query) use (&$fired, $table, $competing) {
            if ($fired || ! str_contains(strtolower($query), "from \"{$table}\"") || ! str_contains(strtolower($query), 'for update')) {
                return;
            }
            $fired = true;
            $competing();
        });
    }

    /** probe 接続を既定にして $fn を実行する（別セッションの操作としてコミットされる）。 */
    private function onProbe(Closure $fn): mixed
    {
        $this->probeConnection();
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('probe');
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    private function assertResale(string $code, Closure $fn): void
    {
        try {
            $fn();
            $this->fail("{$code} が投げられるはずが成功した");
        } catch (ResaleException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function liveTicketsAt(int $seatId): int
    {
        return Ticket::where('seat_id', $seatId)->whereNotIn('status', ['released', 'expired', 'resold'])->count();
    }

    public function test_two_purchases_of_same_listing_only_one_succeeds(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->service()->purchase($this->carol, $listing)
        ));

        $this->assertResale('listing_unavailable', fn () => $this->service()->purchase($this->bob, $listing));

        $this->assertSame(1, $this->liveTicketsAt($seatId));
        $this->assertSame(1, Order::where('kind', 'resale')->count());
        $this->assertSame($this->carol->id, Order::where('kind', 'resale')->value('user_id'));
        $this->assertSame(ResaleListingStatus::Sold, $listing->fresh()->status);
    }

    public function test_deadline_crossed_while_waiting_for_lock_is_rejected(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $this->beforeLock('tickets', fn () => $this->travelTo($this->match->kickoff_at->subMinutes(150)));

        $this->assertResale('resale_closed', fn () => $this->service()->purchase($this->bob, $listing));

        $this->assertSame(1, $this->liveTicketsAt($seatId));
        $this->assertSame(0, Order::where('kind', 'resale')->count());
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
    }

    public function test_cancel_racing_purchase_only_one_wins_purchase_first(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->service()->purchase($this->bob, $listing)
        ));

        $this->assertResale('listing_unavailable', fn () => $this->service()->cancel($this->alice, $listing));

        $this->assertSame(ResaleListingStatus::Sold, $listing->fresh()->status);
        $this->assertSame(1, $this->liveTicketsAt($seatId));
        $this->assertSame(TicketStatus::Resold, $listing->ticket->fresh()->status);
    }

    public function test_cancel_racing_purchase_only_one_wins_cancel_first(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->service()->cancel($this->alice, $listing)
        ));

        $this->assertResale('listing_unavailable', fn () => $this->service()->purchase($this->bob, $listing));

        $this->assertSame(ResaleListingStatus::Cancelled, $listing->fresh()->status);
        $this->assertSame(TicketStatus::Paid, $listing->ticket->fresh()->status);
        $this->assertSame(1, Ticket::where('seat_id', $seatId)->count());
        $this->assertSame(0, Order::where('kind', 'resale')->count());
    }

    public function test_same_buyer_concurrent_purchases_serialize_on_advisory_lock(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service()->purchase($this->bob, $this->listing());
        }
        $sixth = $this->listing();
        $seventh = $this->listing();

        $blocked = null;
        $this->beforeLock('tickets', function () use ($seventh, &$blocked) {
            $this->onProbe(function () use ($seventh, &$blocked) {
                try {
                    $this->service()->purchase($this->bob, $seventh);
                } catch (ResaleException $e) {
                    $blocked = $e->errorCode;
                }
            });
        });

        $this->service()->purchase($this->bob, $sixth);

        $this->assertSame('listing_unavailable', $blocked, '同じ buyer の並行購入は advisory lock で待たされる');
        $this->assertSame(ResaleListingStatus::Active, $seventh->fresh()->status);
        $this->assertResale('resale_limit_reached', fn () => $this->service()->purchase($this->bob, $seventh));
        $this->assertSame(6, OrderItem::whereIn('order_id', Order::where('kind', 'resale')->where('user_id', $this->bob->id)->select('id'))->count());
    }

    public function test_different_buyers_do_not_wait_on_each_other(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $waited = null;
        $this->beforeLock('tickets', function () use ($b, &$waited) {
            $this->onProbe(function () use ($b, &$waited) {
                try {
                    $this->service()->purchase($this->carol, $b);
                    $waited = false;
                } catch (ResaleException) {
                    $waited = true;
                }
            });
        });

        $this->service()->purchase($this->bob, $a);

        $this->assertFalse($waited);
    }

    public function test_purchase_waits_on_ticket_row_lock(): void
    {
        $listing = $this->listing();
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select('select id from tickets where id = ? for update', [$listing->ticket_id]);

        // SET LOCAL lock_timeout が効いて、待たされたら listing_unavailable に変換される
        $this->assertResale('listing_unavailable', fn () => $this->service()->purchase($this->bob, $listing));
    }

    public function test_lock_order_is_ticket_then_listing_and_never_allocation(): void
    {
        $listing = $this->listing();
        $other = $this->listing();
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $lower = strtolower($q->sql);
            if (str_contains($lower, 'for update') || str_contains($lower, 'for share')) {
                $sql[] = $lower;
            }
        });
        $tables = fn (array $queries) => array_map(
            fn (string $s) => preg_match('/from "(\w+)"/', $s, $m) ? $m[1] : $s,
            $queries,
        );

        $this->service()->purchase($this->bob, $listing);
        $this->assertSame(['tickets', 'resale_listings'], $tables($sql));

        $sql = [];
        $this->service()->cancel($this->alice, $other);
        $this->assertSame(['tickets', 'resale_listings'], $tables($sql));
    }
}
