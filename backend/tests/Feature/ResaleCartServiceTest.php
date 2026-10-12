<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleHoldStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\PurchaseException;
use App\Exceptions\ResaleException;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleHold;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleBoardService;
use App\Services\Resale\ResaleCartService;
use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsResaleTickets;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class ResaleCartServiceTest extends TestCase
{
    use BuildsResaleTickets;
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private TicketType $adult;

    private User $alice;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildResaleFixture();
        $this->carol = User::create(['name' => 'Carol', 'email' => 'c@example.com', 'password' => 'x']);
    }

    private function cart(): ResaleCartService
    {
        return app(ResaleCartService::class);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function closesAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2030-03-01 10:00:00', 'UTC')->subMinutes(150);
    }

    /** alice が出品する（既定）。 */
    private function listing(?User $seller = null): ResaleListing
    {
        $seller ??= $this->alice;

        return app(ResaleService::class)->list($seller, $this->paidTicket($seller), 4000);
    }

    private function rawHold(ResaleListing $listing, User $user, CarbonImmutable $expiresAt, ResaleHoldStatus $status = ResaleHoldStatus::Held): ResaleHold
    {
        return ResaleHold::create([
            'listing_id' => $listing->id, 'user_id' => $user->id, 'match_id' => $listing->match_id,
            'status' => $status, 'expires_at' => $expiresAt,
        ]);
    }

    private function assertResale(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("{$code} が投げられるはずが成功した");
        } catch (ResaleException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function assertPurchase(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("{$code} が投げられるはずが成功した");
        } catch (PurchaseException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    /** 最初の `for update` クエリの直後に、時刻を $to へ 1 回だけ進める（ロック待ちの間に時間が過ぎた状況）。 */
    private function jumpAfterFirstLock(CarbonImmutable $to): void
    {
        $fired = false;
        DB::listen(function ($query) use (&$fired, $to) {
            if (! $fired && str_contains(strtolower($query->sql), 'for update')) {
                $fired = true;
                $this->travelTo($to);
            }
        });
    }

    // hold

    public function test_hold_creates_with_ten_minute_expiry(): void
    {
        $listing = $this->listing();

        $r = $this->cart()->hold($this->bob, $listing->id);

        $this->assertTrue($r->created);
        $this->assertSame(ResaleHoldStatus::Held, $r->hold->status);
        $this->assertTrue($r->hold->expires_at->equalTo($this->now()->addMinutes(10)));
        $this->assertTrue($r->now->equalTo($this->now()));
        $this->assertSame($this->bob->id, $r->hold->user_id);
        $this->assertSame($listing->match_id, $r->hold->match_id);
    }

    public function test_hold_expiry_is_capped_at_listing_close(): void
    {
        $listing = $this->listing();
        $this->travelTo($this->closesAt()->subMinutes(3));

        $r = $this->cart()->hold($this->bob, $listing->id);

        $this->assertTrue($r->hold->expires_at->equalTo($this->closesAt()));
    }

    public function test_hold_at_exact_close_is_rejected(): void
    {
        $listing = $this->listing();
        $this->travelTo($this->closesAt());

        $this->assertResale('resale_closed', fn () => $this->cart()->hold($this->bob, $listing->id));
        $this->assertSame(0, ResaleHold::count());
    }

    public function test_hold_rejections(): void
    {
        $own = $this->listing();
        $this->assertResale('own_listing', fn () => $this->cart()->hold($this->alice, $own->id));

        foreach ([ResaleListingStatus::Cancelled, ResaleListingStatus::Sold, ResaleListingStatus::Expired] as $status) {
            $l = $this->listing();
            $l->update(['status' => $status]);
            $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->bob, $l->id));
        }

        $unpaid = $this->listing();
        $unpaid->ticket->update(['status' => TicketStatus::Held]);
        $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->bob, $unpaid->id));

        $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->bob, 999999));

        $open = $this->listing();
        $this->match->update(['status' => 'scheduled']);
        $this->assertResale('resale_closed', fn () => $this->cart()->hold($this->bob, $open->id));
        $this->assertSame(0, ResaleHold::count());
    }

    public function test_hold_limit(): void
    {
        config(['ticketing.max_tickets_per_order' => 3]);
        for ($i = 0; $i < 2; $i++) {
            $this->paidTicket($this->bob, null, 4220, OrderKind::Resale);
        }
        $listing = $this->listing();
        $other = $this->listing();

        $this->assertTrue($this->cart()->hold($this->bob, $listing->id)->created);

        $this->cart()->release($this->bob, ResaleHold::firstOrFail()->id);
        $this->paidTicket($this->bob, null, 4220, OrderKind::Resale);
        $this->assertResale('resale_limit_reached', fn () => $this->cart()->hold($this->bob, $other->id));
    }

    public function test_other_hold_one_second_before_expiry_blocks(): void
    {
        $listing = $this->listing();
        $this->rawHold($listing, $this->bob, $this->now()->addSecond());

        $this->assertResale('listing_unavailable', fn () => $this->cart()->hold($this->carol, $listing->id));
        $this->assertSame(1, ResaleHold::count());
    }

    public function test_other_hold_at_exact_expiry_is_taken_over(): void
    {
        $listing = $this->listing();
        $old = $this->rawHold($listing, $this->bob, $this->now());

        $r = $this->cart()->hold($this->carol, $listing->id);

        $this->assertTrue($r->created);
        $this->assertSame(ResaleHoldStatus::Expired, $old->fresh()->status);
        $this->assertSame($this->carol->id, ResaleHold::live($this->now())->where('listing_id', $listing->id)->value('user_id'));
    }

    public function test_rehold_same_listing_is_a_noop(): void
    {
        $listing = $this->listing();
        $first = $this->cart()->hold($this->bob, $listing->id);
        $this->travelTo($this->now()->addMinutes(5));

        $again = $this->cart()->hold($this->bob, $listing->id);

        $this->assertFalse($again->created);
        $this->assertSame($first->hold->id, $again->hold->id);
        $this->assertTrue($again->hold->expires_at->equalTo($first->hold->expires_at));
        $this->assertSame(1, ResaleHold::count());
    }

    public function test_second_listing_is_rejected_while_holding_then_allowed_after_expiry(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $first = $this->cart()->hold($this->bob, $a->id);

        $this->assertResale('resale_hold_exists', fn () => $this->cart()->hold($this->bob, $b->id));

        $this->travelTo($first->hold->expires_at);
        $r = $this->cart()->hold($this->bob, $b->id);

        $this->assertTrue($r->created);
        $this->assertSame(ResaleHoldStatus::Expired, $first->hold->fresh()->status);
    }

    // current / release

    public function test_current_returns_only_live_hold_of_buyer(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;

        $this->assertSame($hold->id, $this->cart()->current($this->bob, $listing->match_id)->hold?->id);
        $this->assertNull($this->cart()->current($this->carol, $listing->match_id)->hold);

        $this->travelTo($hold->expires_at);
        $current = $this->cart()->current($this->bob, $listing->match_id);
        $this->assertNull($current->hold);
        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status, 'current は書き換えない');
    }

    public function test_release_marks_released_or_expired(): void
    {
        $a = $this->listing();
        $hold = $this->cart()->hold($this->bob, $a->id)->hold;

        $r = $this->cart()->release($this->bob, $hold->id);
        $this->assertTrue($r->released);
        $this->assertSame(ResaleHoldStatus::Released, $hold->fresh()->status);

        $again = $this->cart()->release($this->bob, $hold->id);
        $this->assertFalse($again->released);
        $this->assertSame(ResaleHoldStatus::Released, $hold->fresh()->status);

        $late = $this->rawHold($a, $this->bob, $this->now());
        $r = $this->cart()->release($this->bob, $late->id);
        $this->assertFalse($r->released);
        $this->assertSame(ResaleHoldStatus::Expired, $late->fresh()->status);
    }

    public function test_release_of_others_hold_is_not_found(): void
    {
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;

        $this->expectException(ModelNotFoundException::class);
        $this->cart()->release($this->carol, $hold->id);
    }

    // checkout

    public function test_checkout_buys_the_listing(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;

        $r = $this->cart()->checkout($this->bob, $hold->id);

        $this->assertTrue($r->created);
        $this->assertSame(TicketStatus::Resold, $listing->ticket->fresh()->status);
        $listing->refresh();
        $this->assertSame(ResaleListingStatus::Sold, $listing->status);
        $this->assertSame(TicketStatus::Paid, $listing->newTicket->status);
        $order = $r->order->fresh();
        $this->assertSame(OrderKind::Resale, $order->kind);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame($this->bob->id, $order->user_id);
        $this->assertSame(4000 + 220, $order->total);
        $this->assertSame($order->id, $listing->buyer_order_id);
        $hold->refresh();
        $this->assertSame(ResaleHoldStatus::Purchased, $hold->status);
        $this->assertSame($order->id, $hold->order_id);
    }

    public function test_checkout_is_idempotent(): void
    {
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
        $first = $this->cart()->checkout($this->bob, $hold->id);
        $orders = Order::count();
        $tickets = Ticket::count();

        $again = $this->cart()->checkout($this->bob, $hold->id);

        $this->assertFalse($again->created);
        $this->assertSame($first->order->id, $again->order->id);
        $this->assertSame($orders, Order::count());
        $this->assertSame($tickets, Ticket::count());
        $this->assertSame(1, OrderItem::where('order_id', $first->order->id)->count());
    }

    public function test_checkout_one_second_before_expiry_succeeds(): void
    {
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
        $this->travelTo($hold->expires_at->subSecond());

        $this->assertTrue($this->cart()->checkout($this->bob, $hold->id)->created);
    }

    public function test_checkout_at_expiry_is_cart_expired_and_marks_expired(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $this->travelTo($hold->expires_at);

        $this->assertPurchase('cart_expired', fn () => $this->cart()->checkout($this->bob, $hold->id));

        $this->assertSame(ResaleHoldStatus::Expired, $hold->fresh()->status);
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(0, Order::where('kind', 'resale')->count());
    }

    public function test_checkout_of_released_hold_is_cart_expired(): void
    {
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
        $this->cart()->release($this->bob, $hold->id);

        $this->assertPurchase('cart_expired', fn () => $this->cart()->checkout($this->bob, $hold->id));
        $this->assertSame(ResaleHoldStatus::Released, $hold->fresh()->status);
    }

    public function test_checkout_payment_failure_changes_nothing(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        config(['ticketing.mock_payment_outcome' => 'declined']);

        $this->assertResale('payment_failed', fn () => $this->cart()->checkout($this->bob, $hold->id));

        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status);
        $this->assertNull($hold->fresh()->order_id);
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(TicketStatus::Paid, $listing->ticket->fresh()->status);
        $this->assertSame(0, Order::where('kind', 'resale')->count());
    }

    public function test_checkout_after_close_is_resale_closed(): void
    {
        $listing = $this->listing();
        $hold = $this->rawHold($listing, $this->bob, $this->closesAt()->addHour());
        $this->travelTo($this->closesAt());

        $this->assertResale('resale_closed', fn () => $this->cart()->checkout($this->bob, $hold->id));
        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status);
    }

    public function test_checkout_rechecks_limit(): void
    {
        config(['ticketing.max_tickets_per_order' => 1]);
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
        $this->paidTicket($this->bob, null, 4220, OrderKind::Resale);

        $this->assertResale('resale_limit_reached', fn () => $this->cart()->checkout($this->bob, $hold->id));
    }

    public function test_checkout_of_others_hold_is_not_found(): void
    {
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;

        $this->expectException(ModelNotFoundException::class);
        $this->cart()->checkout($this->carol, $hold->id);
    }

    // cancel / 一覧 / 旧 purchase

    public function test_cancel_is_rejected_while_held_and_allowed_after(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;

        $this->assertResale('listing_held', fn () => app(ResaleService::class)->cancel($this->alice, $listing));

        $this->cart()->release($this->bob, $hold->id);
        $this->assertSame(ResaleListingStatus::Cancelled, app(ResaleService::class)->cancel($this->alice, $listing)->status);

        $other = $this->listing();
        $h2 = $this->cart()->hold($this->bob, $other->id)->hold;
        $this->travelTo($h2->expires_at);
        $this->assertSame(ResaleListingStatus::Cancelled, app(ResaleService::class)->cancel($this->alice, $other)->status);
    }

    public function test_board_excludes_live_holds(): void
    {
        $held = $this->listing();
        $free = $this->listing();
        $hold = $this->cart()->hold($this->bob, $held->id)->hold;
        $ids = fn (User $v) => app(ResaleBoardService::class)->board($v, $this->match)['listings']->pluck('id')->all();

        $this->assertSame([$free->id], $ids($this->carol));
        $this->assertSame([$free->id], $ids($this->bob), '自分が押さえた分も除外');

        $this->travelTo($hold->expires_at);
        $this->assertEqualsCanonicalizing([$held->id, $free->id], $ids($this->carol));
    }

    public function test_legacy_purchase_is_blocked_by_others_live_hold(): void
    {
        $listing = $this->listing();
        $this->cart()->hold($this->bob, $listing->id);

        $this->assertResale('listing_unavailable', fn () => app(ResaleService::class)->purchase($this->carol, $listing));
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
    }

    public function test_legacy_purchase_is_blocked_by_own_live_hold(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;

        $this->assertResale('listing_unavailable', fn () => app(ResaleService::class)->purchase($this->bob, $listing));
        $this->assertSame(ResaleListingStatus::Active, $listing->fresh()->status);
        $this->assertSame(ResaleHoldStatus::Held, $hold->fresh()->status);
    }

    // 押さえ直しのクールダウン

    public function test_rehold_after_release_is_blocked_for_cooldown(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $this->cart()->release($this->bob, $hold->id);
        $releasedAt = $this->now();

        $this->assertResale('resale_hold_cooldown', fn () => $this->cart()->hold($this->bob, $listing->id));

        $this->travelTo($releasedAt->addMinutes(29)->addSeconds(59));
        $this->assertResale('resale_hold_cooldown', fn () => $this->cart()->hold($this->bob, $listing->id));

        $this->travelTo($releasedAt->addMinutes(30));
        $this->assertTrue($this->cart()->hold($this->bob, $listing->id)->created);
    }

    public function test_rehold_right_after_expiry_is_blocked(): void
    {
        $listing = $this->listing();
        $hold = $this->cart()->hold($this->bob, $listing->id)->hold;
        $this->travelTo($hold->expires_at);

        $this->assertResale('resale_hold_cooldown', fn () => $this->cart()->hold($this->bob, $listing->id));

        $this->travelTo($hold->expires_at->addMinutes(30));
        $this->assertTrue($this->cart()->hold($this->bob, $listing->id)->created);
    }

    public function test_cooldown_starts_at_expires_at_for_expired_and_lapsed_holds(): void
    {
        $listing = $this->listing();
        $now = $this->now();
        $mk = fn (ResaleHoldStatus $status, CarbonImmutable $expiresAt) => $this->rawHold($listing, $this->bob, $expiresAt, $status);

        // 整理が遅れて updated_at が新しくても、起点は expires_at
        $old = $mk(ResaleHoldStatus::Expired, $now->subMinutes(29));
        $this->assertResale('resale_hold_cooldown', fn () => $this->cart()->hold($this->bob, $listing->id));

        $old->update(['expires_at' => $now->subMinutes(30)]);
        $this->assertTrue($this->cart()->hold($this->bob, $listing->id)->created);

        ResaleHold::query()->delete();
        $mk(ResaleHoldStatus::Held, $now->subMinutes(29));
        $this->assertResale('resale_hold_cooldown', fn () => $this->cart()->hold($this->bob, $listing->id));
    }

    public function test_cooldown_is_per_listing_and_per_user(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $hold = $this->cart()->hold($this->bob, $a->id)->hold;
        $this->cart()->release($this->bob, $hold->id);

        $this->assertTrue($this->cart()->hold($this->bob, $b->id)->created, '別の出品は対象外');
        $this->cart()->release($this->bob, ResaleHold::where('listing_id', $b->id)->value('id'));

        $this->assertTrue($this->cart()->hold($this->carol, $a->id)->created, '他人の release は影響しない');
    }

    public function test_expire_due_expires_only_overdue_holds(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $overdue = $this->rawHold($a, $this->bob, $this->now());
        $live = $this->rawHold($b, $this->carol, $this->now()->addSecond());
        $released = $this->rawHold($a, $this->alice, $this->now()->subHour(), ResaleHoldStatus::Released);

        app(ResaleService::class)->expireDue($this->now());

        $this->assertSame(ResaleHoldStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(ResaleHoldStatus::Held, $live->fresh()->status);
        $this->assertSame(ResaleHoldStatus::Released, $released->fresh()->status);
    }

    // ロック取得後に now を取る

    public function test_hold_judges_other_hold_expiry_with_time_after_lock(): void
    {
        $listing = $this->listing();
        $this->rawHold($listing, $this->bob, $this->now()->addSeconds(10));

        $this->jumpAfterFirstLock($this->now()->addSeconds(11));

        $this->assertTrue($this->cart()->hold($this->carol, $listing->id)->created);
    }

    public function test_checkout_judges_expiry_with_time_after_lock(): void
    {
        $hold = $this->cart()->hold($this->bob, $this->listing()->id)->hold;
        $this->travelTo($hold->expires_at->subSecond());

        $this->jumpAfterFirstLock($hold->expires_at);

        $this->assertPurchase('cart_expired', fn () => $this->cart()->checkout($this->bob, $hold->id));
        $this->assertSame(ResaleHoldStatus::Expired, $hold->fresh()->status);
    }

    public function test_hold_judges_close_with_time_after_lock(): void
    {
        $listing = $this->listing();
        $this->travelTo($this->closesAt()->subSecond());

        $this->jumpAfterFirstLock($this->closesAt());

        $this->assertResale('resale_closed', fn () => $this->cart()->hold($this->bob, $listing->id));
    }

    public function test_checkout_judges_close_with_time_after_lock(): void
    {
        $listing = $this->listing();
        $hold = $this->rawHold($listing, $this->bob, $this->closesAt()->addHour());
        $this->travelTo($this->closesAt()->subSecond());

        $this->jumpAfterFirstLock($this->closesAt());

        $this->assertResale('resale_closed', fn () => $this->cart()->checkout($this->bob, $hold->id));
    }
}
