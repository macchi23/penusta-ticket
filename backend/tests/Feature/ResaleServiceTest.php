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
use App\Models\SeasonPassSeat;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleCartService;
use App\Services\Resale\ResaleService;
use App\Services\Resale\ResaleWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class ResaleServiceTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private Block $reserved;

    private Block $free;

    private TicketType $adult;

    private User $alice;

    private User $bob;

    private int $seatNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-02-01 00:00:00', 'UTC'));

        $this->buildSaleFixture();
        $this->reserved = $this->makeBlock('M1', 'reserved', 20);
        $this->free = $this->makeBlock('F1', 'unreserved', 5);
        $this->buildSalePricesAndUser();
        $this->bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
    }

    private function service(): ResaleService
    {
        return app(ResaleService::class);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function paidTicket(User $owner, ?Block $block = null, int $price = 5000, OrderKind $kind = OrderKind::Purchase, ?int $typeId = null, ?int $passSeatId = null): Ticket
    {
        $block ??= $this->reserved;
        $seatId = $block->seating_type === 'reserved'
            ? Seat::create(['block_id' => $block->id, 'row_label' => 'A', 'number' => ++$this->seatNo])->id
            : null;

        return $this->paidTicketAt($owner, $block, $seatId, $price, $kind, $typeId, $passSeatId);
    }

    private function paidTicketAt(User $owner, Block $block, ?int $seatId, int $price, OrderKind $kind, ?int $typeId, ?int $passSeatId = null): Ticket
    {
        $order = Order::create([
            'user_id' => $owner->id, 'match_id' => $this->match->id, 'status' => OrderStatus::Paid, 'kind' => $kind,
            'total' => $price, 'channel' => 'web', 'ordered_at' => $this->now(),
        ]);
        $ticket = Ticket::create([
            'match_id' => $this->match->id, 'block_id' => $block->id, 'seat_id' => $seatId,
            'season_pass_seat_id' => $passSeatId, 'status' => TicketStatus::Paid, 'qr_uuid' => (string) Str::uuid(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'ticket_id' => $ticket->id, 'ticket_type_id' => $typeId ?? $this->adult->id, 'price' => $price]);

        return $ticket;
    }

    private function passTicket(User $owner): Ticket
    {
        $pass = TicketType::firstOrCreate(['code' => 'season_pass'], ['name' => '年パス', 'display_order' => 3]);
        $seat = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'P', 'number' => ++$this->seatNo]);
        $row = SeasonPassSeat::create([
            'season_id' => $this->match->season_id, 'user_id' => $owner->id,
            'block_id' => $this->reserved->id, 'seat_id' => $seat->id,
        ]);

        return $this->paidTicketAt($owner, $this->reserved, $seat->id, 0, OrderKind::SeasonPass, $pass->id, $row->id);
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

    private function listing(User $seller, ?Ticket $ticket = null, int $price = 4000): ResaleListing
    {
        return $this->service()->list($seller, $ticket ?? $this->paidTicket($seller), $price);
    }

    // 出品

    public function test_listing_amounts(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $listing = $this->service()->list($this->alice, $ticket, 4005);

        $this->assertSame(5000, $listing->face_price);
        $this->assertSame(4005, $listing->price);
        $this->assertSame(401, $listing->seller_fee); // 400.5 を四捨五入
        $this->assertSame(220, $listing->buyer_fee);
        $this->assertSame(4005 - 401 - 330, $listing->payout);
        $this->assertSame($this->adult->id, $listing->ticket_type_id);
        $this->assertSame(ResaleListingStatus::Active, $listing->status);
        $this->assertEquals($this->match->kickoff_at->subMinutes(150), $listing->closes_at);
        $this->assertSame($this->match->id, $listing->match_id);
        $this->assertSame($this->reserved->id, $listing->block_id);
    }

    public function test_price_bounds(): void
    {
        $this->assertSame(500, $this->listing($this->alice, null, 500)->price);
        $this->assertSame(5000, $this->listing($this->alice, null, 5000)->price);

        $ticket = $this->paidTicket($this->alice);
        $this->assertResale('price_out_of_range', fn () => $this->service()->list($this->alice, $ticket, 499));
        $this->assertResale('price_out_of_range', fn () => $this->service()->list($this->alice, $ticket, 5001));
    }

    public function test_negative_payout_is_rejected(): void
    {
        config(['ticketing.resale.min_price' => 100, 'ticketing.resale.transfer_fee' => 600]);
        $ticket = $this->paidTicket($this->alice);

        $this->assertResale('price_out_of_range', fn () => $this->service()->list($this->alice, $ticket, 500));
        $this->assertSame(30, $this->service()->list($this->alice, $ticket, 700)->payout);
    }

    public function test_face_price_below_minimum_is_not_listable(): void
    {
        $ticket = $this->paidTicket($this->alice, null, 400);
        $this->assertResale('not_listable', fn () => $this->service()->list($this->alice, $ticket, 400));
    }

    public function test_listing_outside_window(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $this->makeSalePhases('presale');
        $this->assertResale('resale_closed', fn () => $this->service()->list($this->alice, $ticket, 4000));

        $this->makeSalePhases('general');
        $this->travelTo($this->match->kickoff_at->subMinutes(150));
        $this->assertResale('resale_closed', fn () => $this->service()->list($this->alice, $ticket, 4000));

        $this->travelTo($this->match->kickoff_at->subMinutes(151));
        $this->assertSame(ResaleListingStatus::Active, $this->service()->list($this->alice, $ticket, 4000)->status);
    }

    public function test_listing_requires_on_sale_match(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $this->match->update(['status' => 'scheduled']);
        $this->assertResale('resale_closed', fn () => $this->service()->list($this->alice, $ticket, 4000));
    }

    public function test_listing_without_general_phase_is_closed(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $this->match->salePhases()->where('kind', 'general')->delete();
        $this->assertResale('resale_closed', fn () => $this->service()->list($this->alice, $ticket, 4000));
    }

    public function test_only_owner_can_list(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $this->assertResale('not_owner', fn () => $this->service()->list($this->bob, $ticket, 4000));
    }

    public function test_only_paid_tickets_can_be_listed(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $ticket->update(['status' => TicketStatus::Held, 'expires_at' => $this->now()->addMinutes(5)]);
        $this->assertResale('not_listable', fn () => $this->service()->list($this->alice, $ticket, 4000));
    }

    public function test_resale_ticket_cannot_be_relisted(): void
    {
        $cart = app(ResaleCartService::class);
        $listing = $this->listing($this->alice);
        $cart->checkout($this->bob, $cart->hold($this->bob, $listing->id)->hold->id);
        $bought = $listing->fresh();
        $newTicket = Ticket::findOrFail($bought->new_ticket_id);

        $this->assertResale('not_listable', fn () => $this->service()->list($this->bob, $newTicket, 3000));
    }

    public function test_double_listing_is_rejected_but_relist_after_cancel_is_allowed(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $first = $this->service()->list($this->alice, $ticket, 4000);
        $this->assertResale('not_listable', fn () => $this->service()->list($this->alice, $ticket, 3000));

        $this->service()->cancel($this->alice, $first);
        $this->assertSame(ResaleListingStatus::Cancelled, $first->fresh()->status);

        $second = $this->service()->list($this->alice, $ticket, 3000);
        $this->assertSame(ResaleListingStatus::Active, $second->status);
    }

    public function test_partial_unique_index_blocks_second_live_listing(): void
    {
        $listing = $this->listing($this->alice);
        $this->expectException(QueryException::class);
        ResaleListing::create($listing->only(['ticket_id', 'seller_user_id', 'match_id', 'block_id', 'ticket_type_id', 'face_price', 'price', 'seller_fee', 'buyer_fee', 'payout', 'closes_at']) + ['status' => 'active']);
    }

    public function test_cancel_by_non_seller_and_after_cancel(): void
    {
        $listing = $this->listing($this->alice);
        $this->assertResale('not_owner', fn () => $this->service()->cancel($this->bob, $listing));
        $this->service()->cancel($this->alice, $listing);
        $this->assertResale('listing_unavailable', fn () => $this->service()->cancel($this->alice, $listing));
    }

    // 年パス券

    public function test_season_pass_ticket_listing(): void
    {
        $ticket = $this->passTicket($this->alice);

        $this->assertResale('price_out_of_range', fn () => $this->service()->list($this->alice, $ticket, 5000));
        $this->assertResale('price_out_of_range', fn () => $this->service()->list($this->alice, $ticket, 3751));

        $listing = $this->service()->list($this->alice, $ticket, 3750);
        $this->assertSame(3750, $listing->face_price);
        $this->assertSame($this->adult->id, $listing->ticket_type_id);
    }

    public function test_season_pass_face_price_is_floored(): void
    {
        $this->match->prices()->update(['price' => 5003]);
        $listing = $this->service()->list($this->alice, $this->passTicket($this->alice), 3000);
        $this->assertSame(3752, $listing->face_price); // 3752.25
    }

    // 失効とコマンド

    public function test_expire_due(): void
    {
        $due = $this->listing($this->alice);
        $later = $this->listing($this->alice);
        $later->update(['closes_at' => $this->now()->addHour()]);
        $due->update(['closes_at' => $this->now()]);
        $sold = $this->listing($this->alice);
        $cart = app(ResaleCartService::class);
        $cart->checkout($this->bob, $cart->hold($this->bob, $sold->id)->hold->id);
        $sold->update(['closes_at' => $this->now()->subHour()]);

        $this->assertSame(1, $this->service()->expireDue($this->now()));
        $this->assertSame(ResaleListingStatus::Expired, $due->fresh()->status);
        $this->assertSame(ResaleListingStatus::Active, $later->fresh()->status);
        $this->assertSame(ResaleListingStatus::Sold, $sold->fresh()->status);
    }

    public function test_expire_command(): void
    {
        $due = $this->listing($this->alice);
        $due->update(['closes_at' => $this->now()->subMinute()]);

        $this->assertSame(0, Artisan::call('resale:expire-listings'));
        $this->assertSame(ResaleListingStatus::Expired, $due->fresh()->status);
    }

    public function test_expire_command_is_scheduled_every_minute(): void
    {
        $events = array_filter(
            app(Schedule::class)->events(),
            fn ($e) => Event::normalizeCommand($e->command) === 'php artisan resale:expire-listings'
        );
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', reset($events)->expression);
    }

    // ResaleWindow

    public function test_window_can_view(): void
    {
        $window = app(ResaleWindow::class);
        $this->assertTrue($window->canView($this->alice, $this->match, $this->now()));
        $this->assertFalse($window->canView(null, $this->match, $this->now()));
        $this->assertFalse($window->canView($this->alice, $this->match, $this->match->kickoff_at->toImmutable()->subMinutes(150)));

        DB::table('matches')->where('id', $this->match->id)->update(['status' => 'scheduled']);
        $this->assertFalse($window->canView($this->alice, $this->match->fresh(), $this->now()));
    }
}
