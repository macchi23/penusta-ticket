<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleHoldStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ResaleException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleHold;
use App\Models\ResaleListing;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleCartService;
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
class ResaleCartConcurrencyTest extends TestCase
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

    private function cart(): ResaleCartService
    {
        return app(ResaleCartService::class);
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

    private function liveHolds(ResaleListing $listing): int
    {
        return ResaleHold::live(CarbonImmutable::now())->where('listing_id', $listing->id)->count();
    }

    public function test_two_holds_of_same_listing_only_one_succeeds(): void
    {
        $listing = $this->listing();
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->cart()->hold($this->carol, $listing->id)
        ));

        $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->bob, $listing->id));

        $this->assertSame(1, $this->liveHolds($listing));
        $this->assertSame($this->carol->id, ResaleHold::where('status', 'held')->value('user_id'));
    }

    public function test_hold_racing_cancel_hold_first(): void
    {
        $listing = $this->listing();
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->cart()->hold($this->bob, $listing->id)
        ));

        $this->assertResale('listing_held', fn () => $this->service()->cancel($this->alice, $listing));

        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(1, $this->liveHolds($listing));
    }

    public function test_hold_racing_cancel_cancel_first(): void
    {
        $listing = $this->listing();
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->service()->cancel($this->alice, $listing)
        ));

        $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->bob, $listing->id));

        $this->assertSame(ResaleListingStatus::Cancelled, $listing->fresh()->status);
        $this->assertSame(0, ResaleHold::count());
    }

    public function test_same_buyer_concurrent_holds_serialize_on_advisory_lock(): void
    {
        $first = $this->listing();
        $second = $this->listing();

        $blocked = null;
        $this->beforeLock('tickets', function () use ($second, &$blocked) {
            $this->onProbe(function () use ($second, &$blocked) {
                try {
                    $this->cart()->hold($this->bob, $second->id);
                } catch (ResaleException $e) {
                    $blocked = $e->errorCode;
                }
            });
        });

        $this->cart()->hold($this->bob, $first->id);

        $this->assertSame('listing_unavailable', $blocked, '同じ buyer の並行 hold は advisory lock で待たされる');
        $this->assertSame(1, ResaleHold::where('status', 'held')->count());
        $this->assertResale('resale_hold_exists', fn () => $this->cart()->hold($this->bob, $second->id));
    }

    public function test_expire_due_does_not_wait_for_locked_rows(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $listing->update(['closes_at' => CarbonImmutable::now()->subMinute()]);
        $hold->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

        // checkout がロック中、という状況
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select('select id from resale_listings where id = ? for update', [$listing->id]);
        $probe->select('select id from resale_holds where id = ? for update', [$hold->id]);

        DB::statement("SET lock_timeout = '200ms'");
        $count = $this->service()->expireDue(CarbonImmutable::now());

        $this->assertSame(0, $count);
        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status);
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);

        $probe->rollBack();
        $this->assertSame(1, $this->service()->expireDue(CarbonImmutable::now()));
        $this->assertSame(ResaleHoldStatus::Expired, $hold->fresh()->status);
    }

    public function test_stale_cleanup_skips_locked_rows_instead_of_waiting(): void
    {
        $x = $this->listing();
        $y = $this->listing();
        $past = CarbonImmutable::now()->subMinute();
        ResaleHold::create(['listing_id' => $y->id, 'user_id' => $this->bob->id, 'match_id' => $y->match_id, 'status' => ResaleHoldStatus::Held, 'expires_at' => $past]);
        $theirs = ResaleHold::create(['listing_id' => $x->id, 'user_id' => $this->carol->id, 'match_id' => $x->match_id, 'status' => ResaleHoldStatus::Held, 'expires_at' => $past]);

        // carol が Y を hold しようとして自分の期限切れ行（X）を掴んでいる、という状況
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select('select id from resale_holds where id = ? for update', [$theirs->id]);

        config(['ticketing.resale.lock_timeout_ms' => 5000]);
        $started = microtime(true);
        $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->bob, $x->id));

        $this->assertLessThan(2.0, microtime(true) - $started, '掴まれた行を待たない');
        $this->assertSame(ResaleHoldStatus::Held, $theirs->fresh()->status);
    }

    public function test_checkout_waits_on_hold_row_lock(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select('select id from resale_holds where id = ? for update', [$hold->id]);

        $this->assertResale('listing_unavailable', fn () => $this->cart()->checkout($this->bob, $hold->id));
        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status);
    }

    public function test_lock_order_is_advisory_tickets_listings_holds(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $lower = strtolower($q->sql);
            if (str_contains($lower, 'pg_advisory_xact_lock')) {
                $sql[] = 'advisory';
            } elseif (str_starts_with($lower, 'update resale_holds')) {
                $sql[] = 'resale_holds';
            } elseif (str_contains($lower, 'for update') || str_contains($lower, 'for share')) {
                $sql[] = preg_match('/from "(\w+)"/', $lower, $m) ? $m[1] : $lower;
            } elseif (preg_match('/^(update|insert into) "resale_holds"/', $lower)) {
                $sql[] = 'resale_holds';
            }
        });
        $order = function () use (&$sql) {
            $out = [];
            foreach ($sql as $t) {
                if (end($out) !== $t) {
                    $out[] = $t;
                }
            }
            $sql = [];

            return $out;
        };
        $expected = ['advisory', 'tickets', 'resale_listings', 'resale_holds'];

        $hold = $this->cart()->hold($this->bob, $a->id)->hold;
        $this->assertSame($expected, $order());

        $this->cart()->checkout($this->bob, $hold->id);
        $this->assertSame($expected, $order());

        $other = $this->cart()->hold($this->carol, $b->id)->hold;
        $order();
        $this->cart()->release($this->carol, $other->id);
        $this->assertSame(['advisory', 'resale_holds'], $order());
    }
}
