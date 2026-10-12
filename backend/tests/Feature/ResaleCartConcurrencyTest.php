<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleHoldStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\PurchaseException;
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

    /** bob の仮押さえを期限切れにする（行は held のまま、時刻条件で無効）。 */
    private function expireHoldOf(ResaleHold $hold): void
    {
        $hold->update(['expires_at' => CarbonImmutable::now()->subSecond()]);
    }

    public function test_takeover_after_expiry_only_one_buyer_checks_out(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $bobHold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $this->expireHoldOf($bobHold);
        $this->beforeLock('tickets', fn () => $this->onProbe(function () use ($listing) {
            $hold = $this->cart()->hold($this->carol, $listing->id)->hold;
            $this->cart()->checkout($this->carol, $hold->id);
        }));

        try {
            $this->cart()->checkout($this->bob, $bobHold->id);
            $this->fail('cart_expired が投げられるはずが成功した');
        } catch (PurchaseException $e) {
            $this->assertSame('cart_expired', $e->errorCode);
        }

        $this->assertSame(1, $this->liveTicketsAt($seatId));
        $this->assertSame(1, Order::where('kind', 'resale')->count());
        $this->assertSame($this->carol->id, Order::where('kind', 'resale')->value('user_id'));
        $this->assertSame(ResaleListingStatus::Sold, $listing->fresh()->status);
    }

    public function test_deadline_crossed_while_waiting_for_checkout_lock_is_rejected(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $hold->update(['expires_at' => $listing->closes_at->addHour()]);
        $this->travelTo($listing->closes_at->subMinute());
        $this->beforeLock('tickets', fn () => $this->travelTo($listing->closes_at));

        $this->assertResale('resale_closed', fn () => $this->cart()->checkout($this->bob, $hold->id));

        $this->assertSame(1, $this->liveTicketsAt($seatId));
        $this->assertSame(0, Order::where('kind', 'resale')->count());
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
    }

    public function test_cancel_racing_checkout_checkout_first(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->cart()->checkout($this->bob, $hold->id)
        ));

        $this->assertResale('listing_unavailable', fn () => $this->service()->cancel($this->alice, $listing));

        $this->assertSame(ResaleListingStatus::Sold, $listing->fresh()->status);
        $this->assertSame(1, $this->liveTicketsAt($seatId));
        $this->assertSame(TicketStatus::Resold, $listing->ticket->fresh()->status);
    }

    public function test_cancel_racing_checkout_cancel_first(): void
    {
        $listing = $this->listing();
        $seatId = $listing->ticket->seat_id;
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $this->expireHoldOf($hold);
        $this->beforeLock('tickets', fn () => $this->onProbe(
            fn () => $this->service()->cancel($this->alice, $listing)
        ));

        try {
            $this->cart()->checkout($this->bob, $hold->id);
            $this->fail('cart_expired が投げられるはずが成功した');
        } catch (PurchaseException $e) {
            $this->assertSame('cart_expired', $e->errorCode);
        }

        $this->assertSame(ResaleListingStatus::Cancelled, $listing->fresh()->status);
        $this->assertSame(TicketStatus::Paid, $listing->ticket->fresh()->status);
        $this->assertSame(1, Ticket::where('seat_id', $seatId)->count());
        $this->assertSame(0, Order::where('kind', 'resale')->count());
    }

    public function test_same_buyer_checkout_and_hold_serialize_on_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
            $this->cart()->checkout($this->bob, $hold->id);
        }
        $sixth = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
        $seventh = $this->listing();

        $blocked = null;
        $this->beforeLock('tickets', function () use ($seventh, &$blocked) {
            $this->onProbe(function () use ($seventh, &$blocked) {
                try {
                    $this->cart()->hold($this->bob, $seventh->id);
                } catch (ResaleException $e) {
                    $blocked = $e->errorCode;
                }
            });
        });

        $this->cart()->checkout($this->bob, $sixth->id);

        $this->assertSame('listing_unavailable', $blocked, '同じ buyer の並行操作は advisory lock で待たされる');
        $this->assertSame(0, ResaleHold::where('listing_id', $seventh->id)->count());
        $this->assertResale('resale_limit_reached', fn () => $this->cart()->hold($this->bob, $seventh->id));
        $this->assertSame(6, OrderItem::whereIn('order_id', Order::where('kind', 'resale')->where('user_id', $this->bob->id)->select('id'))->count());
    }

    public function test_different_buyers_do_not_wait_on_each_other(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $holdA = $this->cart()->hold($this->bob, $a->id)->hold;
        $waited = null;
        $this->beforeLock('tickets', function () use ($b, &$waited) {
            $this->onProbe(function () use ($b, &$waited) {
                try {
                    $hold = $this->cart()->hold($this->carol, $b->id)->hold;
                    $this->cart()->checkout($this->carol, $hold->id);
                    $waited = false;
                } catch (ResaleException) {
                    $waited = true;
                }
            });
        });

        $this->cart()->checkout($this->bob, $holdA->id);

        $this->assertFalse($waited);
        $this->assertSame(2, Order::where('kind', 'resale')->count());
    }

    public function test_checkout_waits_on_ticket_row_lock(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $probe = $this->probeConnection();
        $probe->beginTransaction();
        $probe->select('select id from tickets where id = ? for update', [$listing->ticket_id]);

        // SET LOCAL lock_timeout が効いて、待たされたら listing_unavailable に変換される
        $this->assertResale('listing_unavailable', fn () => $this->cart()->checkout($this->bob, $hold->id));
        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status);
    }

    public function test_cancel_lock_order_is_ticket_then_listing_and_never_allocation(): void
    {
        $other = $this->listing();
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $lower = strtolower($q->sql);
            if (str_contains($lower, 'for update') || str_contains($lower, 'for share')) {
                $sql[] = preg_match('/from "(\w+)"/', $lower, $m) ? $m[1] : $lower;
            }
        });

        $this->service()->cancel($this->alice, $other);

        $this->assertSame(['tickets', 'resale_listings'], $sql);
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
