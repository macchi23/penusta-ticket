<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Exceptions\SeasonPassIssueException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalePhase;
use App\Models\SeasonPassSeat;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\SeasonPass\SalePhaseProvisioner;
use App\Services\SeasonPass\SeasonPassIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class SeasonPassIssuerTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private Block $reserved;

    private Block $free;

    private TicketType $adult;

    private TicketType $pass;

    private User $alice;

    private User $bob;

    /** @var array<int, Seat> */
    private array $seats = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ticketing.demo_auth_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));

        $this->buildSaleFixture();
        $this->reserved = $this->makeBlock('M1', 'reserved', 10);
        $this->free = $this->makeBlock('F1', 'unreserved', 2);
        foreach (range(1, 5) as $n) {
            $this->seats[$n] = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => $n]);
        }
        $this->buildSalePricesAndUser();
        $this->pass = TicketType::create(['name' => '年パス', 'code' => 'season_pass', 'display_order' => 3]);
        $this->bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
    }

    public function test_provision_creates_missing_phases_only_and_is_idempotent(): void
    {
        $this->match->salePhases()->delete();
        $provisioner = app(SalePhaseProvisioner::class);

        $this->assertSame(0, $provisioner->provision($this->match));
        $phases = $this->match->salePhases()->get();
        $this->assertSame(['fanclub_presale', 'general'], $phases->pluck('kind')->map->value->all());
        $this->assertTrue($phases[0]->starts_at->equalTo($this->match->kickoff_at->subDays(42)));
        $this->assertTrue($phases[1]->ends_at->equalTo($this->match->kickoff_at));

        $phases[0]->update(['ends_at' => $phases[0]->ends_at->subHour()]);
        $before = SalePhase::orderBy('id')->get()->toArray();
        $provisioner->provision($this->match);
        $this->assertSame($before, SalePhase::orderBy('id')->get()->toArray());
    }

    public function test_provision_issues_season_pass_tickets(): void
    {
        $this->match->salePhases()->delete();
        $this->pass(1);

        $this->assertSame(1, app(SalePhaseProvisioner::class)->provision($this->match));
        $this->assertSame(2, $this->match->salePhases()->count());
    }

    public function test_issue_creates_order_item_and_ticket_per_seat_and_is_idempotent(): void
    {
        $reservedPass = $this->pass(1);
        $freePass = $this->pass(null, $this->free, $this->bob);

        $this->assertSame(2, $this->issue());

        $order = Order::where('user_id', $this->alice->id)->firstOrFail();
        $this->assertSame(OrderKind::SeasonPass, $order->kind);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(0, $order->total);
        $this->assertSame('system', $order->channel);
        $this->assertNull($order->sale_phase_id);
        $this->assertNull($order->expires_at);
        $this->assertTrue($order->ordered_at->equalTo(CarbonImmutable::now()));

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($this->pass->id, $item->ticket_type_id);
        $this->assertSame(0, $item->price);
        $ticket = $item->ticket;
        $this->assertSame('paid', $ticket->status->value);
        $this->assertSame($this->seats[1]->id, $ticket->seat_id);
        $this->assertSame($reservedPass->id, $ticket->season_pass_seat_id);
        $this->assertNull(Ticket::where('season_pass_seat_id', $freePass->id)->firstOrFail()->seat_id);

        $this->assertSame(0, $this->issue());
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Ticket::count());
        $this->assertSame(2, OrderItem::count());
    }

    public function test_only_missing_seats_are_issued_on_second_run(): void
    {
        $this->pass(1);
        $this->assertSame(1, $this->issue());
        $this->pass(2, $this->reserved, $this->bob);

        $this->assertSame(1, $this->issue());
        $this->assertSame(2, Ticket::count());
    }

    public function test_non_home_block_raises_and_creates_nothing(): void
    {
        $this->pass(1);
        $this->pass(null, $this->free, $this->bob);
        MatchBlockAllocation::where('block_id', $this->free->id)->update(['allocation' => 'buffer']);

        $this->expectException(SeasonPassIssueException::class);
        try {
            $this->issue();
        } finally {
            $this->assertSame(0, Order::count());
            $this->assertSame(0, Ticket::count());
        }
    }

    public function test_inconsistent_season_pass_rows_raise_and_create_nothing(): void
    {
        $otherBlock = $this->makeBlock('M2', 'reserved', 10);
        $foreignSeat = Seat::create(['block_id' => $otherBlock->id, 'row_label' => 'A', 'number' => 1]);
        $cases = [
            'reserved without seat' => [$this->reserved, null],
            'unreserved with seat' => [$this->free, $this->seats[1]->id],
            'seat of another block' => [$this->reserved, $foreignSeat->id],
        ];

        foreach ($cases as $label => [$block, $seatId]) {
            $row = SeasonPassSeat::create([
                'season_id' => $this->match->season_id, 'user_id' => $this->alice->id,
                'block_id' => $block->id, 'seat_id' => $seatId,
            ]);
            $this->pass(2, $this->reserved, $this->bob);

            try {
                $this->issue();
                $this->fail("{$label}: SeasonPassIssueException になるはずが成功した");
            } catch (SeasonPassIssueException) {
                $this->assertSame(0, Order::count(), $label);
                $this->assertSame(0, Ticket::count(), $label);
            }

            SeasonPassSeat::query()->delete();
        }
    }

    public function test_general_ticket_on_fixed_seat_raises(): void
    {
        $this->pass(1);
        $this->generalTicket($this->seats[1], 'paid');

        $this->expectException(SeasonPassIssueException::class);
        $this->issue();
    }

    public function test_live_held_on_fixed_seat_raises_but_expired_held_is_reclaimed(): void
    {
        $this->pass(1);
        $this->pass(2, $this->reserved, $this->bob);
        $this->generalTicket($this->seats[1], 'held', CarbonImmutable::now()->subMinute());
        $live = $this->generalTicket($this->seats[2], 'held', CarbonImmutable::now()->addMinutes(5));

        try {
            $this->issue();
            $this->fail('SeasonPassIssueException になるはずが成功した');
        } catch (SeasonPassIssueException) {
            $this->assertSame(0, Order::count());
        }

        $live->update(['status' => 'released']);
        $this->assertSame(2, $this->issue());
        $this->assertSame('expired', Ticket::where('seat_id', $this->seats[1]->id)->whereNull('season_pass_seat_id')->firstOrFail()->status->value);
    }

    public function test_unreserved_over_capacity_raises(): void
    {
        $carol = User::create(['name' => 'Carol', 'email' => 'c@example.com', 'password' => 'x']);
        $this->pass(null, $this->free, $this->alice);
        $this->pass(null, $this->free, $this->bob);
        $this->pass(null, $this->free, $carol);

        try {
            $this->issue();
            $this->fail('SeasonPassIssueException になるはずが成功した');
        } catch (SeasonPassIssueException) {
            $this->assertSame(0, Ticket::count());
        }
    }

    public function test_unreserved_counts_existing_general_tickets(): void
    {
        $this->pass(null, $this->free, $this->alice);
        $this->pass(null, $this->free, $this->bob);
        $this->generalTicket(null, 'paid', null, $this->free);

        $this->expectException(SeasonPassIssueException::class);
        $this->issue();
    }

    public function test_issue_for_season_provisions_every_match(): void
    {
        $this->pass(1);
        $other = FootballMatch::create([
            'season_id' => $this->match->season_id, 'home_team_id' => $this->match->home_team_id,
            'away_team_id' => $this->match->away_team_id, 'stadium_id' => $this->match->stadium_id,
            'kickoff_at' => CarbonImmutable::parse('2030-04-01 10:00:00', 'UTC'), 'status' => 'on_sale', 'section_label' => 'B',
        ]);
        MatchBlockAllocation::create(['match_id' => $other->id, 'block_id' => $this->reserved->id, 'allocation' => 'home']);

        $this->artisan('season-pass:issue', ['season' => $this->match->season_id])
            ->expectsOutput('issued 2 ticket(s)')
            ->assertSuccessful();
        $this->artisan('season-pass:issue', ['season' => $this->match->season_id])
            ->expectsOutput('issued 0 ticket(s)')
            ->assertSuccessful();

        $this->assertSame(2, Ticket::count());
        $this->assertSame(2, $other->salePhases()->count());
    }

    public function test_seatmap_and_seat_status_reflect_issued_tickets(): void
    {
        $this->pass(1);
        $this->pass(null, $this->free, $this->bob);
        $this->issue();

        $h = ['X-Demo-User-Id' => (string) $this->alice->id];
        $blocks = collect($this->getJson("/api/matches/{$this->match->id}/seatmap", $h)->assertOk()->json('blocks'))->keyBy('id');
        $this->assertSame(9, $blocks[$this->reserved->id]['available']);
        $this->assertSame(1, $blocks[$this->free->id]['available']);

        $rows = $this->getJson("/api/matches/{$this->match->id}/blocks/{$this->reserved->id}/seats", $h)->assertOk()->json('rows');
        $status = collect($rows)->pluck('seats')->flatten(1)->pluck('status', 'id');
        $this->assertSame('sold', $status[$this->seats[1]->id]);
        $this->assertSame('available', $status[$this->seats[2]->id]);
    }

    public function test_season_pass_holder_can_still_add_to_cart_on_that_match(): void
    {
        $this->pass(1);
        $this->issue();
        $headers = ['X-Demo-User-Id' => (string) $this->alice->id];

        $this->postJson("/api/matches/{$this->match->id}/cart/items", ['seat_id' => $this->seats[2]->id], $headers)
            ->assertCreated()
            ->assertJsonPath('cart.status', 'pending');
        $this->postJson("/api/matches/{$this->match->id}/cart/items", ['seat_id' => $this->seats[3]->id], $headers)
            ->assertCreated()
            ->assertJsonCount(2, 'cart.items');

        $this->assertSame(1, Order::where('status', 'pending')->count());
        $this->assertSame(1, Order::where('kind', 'season_pass')->count());
    }

    public function test_cart_limit_counts_only_the_cart_not_issued_tickets(): void
    {
        $this->pass(1);
        $this->issue();
        $max = (int) config('ticketing.max_tickets_per_order');
        $this->assertGreaterThanOrEqual(2, $max);
        $headers = ['X-Demo-User-Id' => (string) $this->alice->id];

        foreach ([2, 3, 4, 5] as $n) {
            $this->postJson("/api/matches/{$this->match->id}/cart/items", ['seat_id' => $this->seats[$n]->id], $headers)->assertCreated();
        }
        $this->postJson("/api/matches/{$this->match->id}/cart/items", ['seat_id' => $this->seats[1]->id], $headers)->assertStatus(409);
    }

    private function issue(): int
    {
        return app(SeasonPassIssuer::class)->issueForMatch($this->match);
    }

    private function pass(?int $seatNo, ?Block $block = null, ?User $user = null): SeasonPassSeat
    {
        return SeasonPassSeat::create([
            'season_id' => $this->match->season_id,
            'user_id' => ($user ?? $this->alice)->id,
            'block_id' => ($block ?? $this->reserved)->id,
            'seat_id' => $seatNo !== null ? $this->seats[$seatNo]->id : null,
        ]);
    }

    private function generalTicket(?Seat $seat, string $status, ?CarbonImmutable $expiresAt = null, ?Block $block = null): Ticket
    {
        return Ticket::create([
            'match_id' => $this->match->id,
            'block_id' => $seat?->block_id ?? $block->id,
            'seat_id' => $seat?->id,
            'status' => $status,
            'qr_uuid' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
        ]);
    }
}
