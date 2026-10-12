<?php

namespace Tests\Feature;

use App\Enums\OrderKind;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Models\FootballMatch;
use App\Models\ResaleListing;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsResaleTickets;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class MyTicketsApiTest extends TestCase
{
    use BuildsResaleTickets;
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private TicketType $adult;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildResaleFixture();
    }

    private function mine(User $user, string $query = ''): array
    {
        return $this->getJson("/api/me/tickets{$query}", $this->h($user))->assertOk()->json('data');
    }

    public function test_guest_is_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me/tickets')->assertUnauthorized();
        $this->getJson('/api/tickets/1')->assertUnauthorized();
    }

    public function test_only_own_paid_and_resold_tickets(): void
    {
        $own = $this->paidTicket($this->alice);
        $resold = $this->paidTicket($this->alice);
        $resold->update(['status' => TicketStatus::Resold]);
        $held = $this->paidTicket($this->alice);
        $held->update(['status' => TicketStatus::Held, 'expires_at' => CarbonImmutable::now()->addMinutes(5)]);
        $released = $this->paidTicket($this->alice);
        $released->update(['status' => TicketStatus::Released]);
        $this->paidTicket($this->bob);

        $rows = $this->mine($this->alice);

        $this->assertEqualsCanonicalizing([$own->id, $resold->id], array_column($rows, 'id'));
        $byId = array_column($rows, null, 'id');
        $this->assertSame('paid', $byId[$own->id]['status']);
        $this->assertSame('resold', $byId[$resold->id]['status']);
        $this->assertCount(1, $this->mine($this->bob));
    }

    public function test_scope_upcoming_and_past(): void
    {
        $upcoming = $this->paidTicket($this->alice);
        $past = FootballMatch::create([
            'season_id' => $this->match->season_id, 'home_team_id' => $this->match->home_team_id,
            'away_team_id' => $this->match->away_team_id, 'stadium_id' => $this->match->stadium_id,
            'kickoff_at' => CarbonImmutable::parse('2030-01-15 10:00:00', 'UTC'), 'status' => 'finished', 'section_label' => 'B',
        ]);
        $old = $this->paidTicket($this->alice, matchId: $past->id);

        $this->assertSame([$upcoming->id], array_column($this->mine($this->alice), 'id'));
        $this->assertSame([$upcoming->id], array_column($this->mine($this->alice, '?scope=upcoming'), 'id'));
        $this->assertSame([$old->id], array_column($this->mine($this->alice, '?scope=past'), 'id'));
        $this->getJson('/api/me/tickets?scope=foo', $this->h($this->alice))->assertUnprocessable();
    }

    public function test_shape_and_listability_of_a_purchased_ticket(): void
    {
        $free = $this->paidTicket($this->alice, $this->free);
        $row = $this->mine($this->alice)[0];

        $this->assertSame($free->id, $row['id']);
        $this->assertSame($this->match->id, $row['match']['id']);
        $this->assertSame('F1', $row['block']['code']);
        $this->assertSame('unreserved', $row['seating_type']);
        $this->assertNull($row['seat']);
        $this->assertSame('adult', $row['ticket_type']['code']);
        $this->assertSame(5000, $row['price']);
        $this->assertSame('purchase', $row['source']);
        $this->assertNull($row['listing']);
        $this->assertTrue($row['can_list']);
        $this->assertFalse($row['can_cancel']);
        $this->assertArrayNotHasKey('qr_uuid', $row);
    }

    public function test_listed_ticket_has_listing_and_can_cancel(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $listing = app(ResaleService::class)->list($this->alice, $ticket, 4000);

        $row = $this->mine($this->alice)[0];

        $this->assertFalse($row['can_list']);
        $this->assertTrue($row['can_cancel']);
        $this->assertSame($listing->id, $row['listing']['id']);
        $this->assertSame('active', $row['listing']['status']);
        $this->assertSame(4000, $row['listing']['price']);
        $this->assertSame(400, $row['listing']['seller_fee']);
        $this->assertSame(330, $row['listing']['transfer_fee']);
        $this->assertSame(4000 - 400 - 330, $row['listing']['payout']);
    }

    public function test_sold_listing_is_attached_to_resold_ticket(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $listing = app(ResaleService::class)->list($this->alice, $ticket, 4000);
        app(ResaleService::class)->purchase($this->bob, $listing);

        $row = $this->mine($this->alice)[0];
        $this->assertSame('resold', $row['status']);
        $this->assertSame('sold', $row['listing']['status']);
        $this->assertFalse($row['can_list']);
        $this->assertFalse($row['can_cancel']);

        $bobs = $this->mine($this->bob);
        $this->assertCount(1, $bobs);
        $this->assertSame('resale', $bobs[0]['source']);
        $this->assertSame(4000, $bobs[0]['price']);
        $this->assertFalse($bobs[0]['can_list']);
    }

    public function test_cancelled_and_expired_listings_are_not_shown_and_ticket_is_listable_again(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $listing = app(ResaleService::class)->list($this->alice, $ticket, 4000);
        $listing->update(['status' => ResaleListingStatus::Cancelled]);

        $row = $this->mine($this->alice)[0];
        $this->assertNull($row['listing']);
        $this->assertTrue($row['can_list']);
    }

    public function test_season_pass_ticket_is_listable(): void
    {
        $this->passTicket($this->alice);

        $row = $this->mine($this->alice)[0];

        $this->assertSame('season_pass', $row['source']);
        $this->assertSame(0, $row['price']);
        $this->assertTrue($row['can_list']);
    }

    public function test_cannot_list_after_window_closes(): void
    {
        $this->paidTicket($this->alice);
        $this->travelTo($this->match->kickoff_at->subMinutes(150));

        $row = $this->mine($this->alice)[0];

        $this->assertFalse($row['can_list']);
    }

    public function test_cannot_list_when_face_price_is_below_minimum(): void
    {
        $this->paidTicket($this->alice, price: 400);

        $this->assertFalse($this->mine($this->alice)[0]['can_list']);
    }

    public function test_no_n_plus_one(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->paidTicket($this->alice);
        }
        $this->passTicket($this->alice);
        $count = fn () => count(DB::getQueryLog());

        DB::enableQueryLog();
        $this->mine($this->alice);
        $few = $count();
        DB::flushQueryLog();
        for ($i = 0; $i < 6; $i++) {
            $this->paidTicket($this->alice, $this->free);
        }
        DB::flushQueryLog();
        $this->mine($this->alice);
        $many = $count();

        $this->assertSame($few, $many);
    }

    // 詳細

    public function test_detail_of_other_users_ticket_is_404(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $this->getJson("/api/tickets/{$ticket->id}", $this->h($this->bob))->assertNotFound();
        $this->getJson('/api/tickets/999999', $this->h($this->bob))->assertNotFound();
        $this->getJson('/api/tickets/abc', $this->h($this->bob))->assertNotFound();
    }

    public function test_detail_shows_qr_and_terms_for_listable_ticket(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $body = $this->getJson("/api/tickets/{$ticket->id}", $this->h($this->alice))->assertOk()->json('ticket');

        $this->assertSame($ticket->qr_uuid, $body['qr_uuid']);
        $this->assertTrue($body['can_list']);
        $this->assertEquals([
            'face_price' => 5000, 'min_price' => 500, 'seller_fee_rate' => 0.1, 'transfer_fee' => 330, 'buyer_fee' => 220,
        ], $body['listing_terms']);
    }

    public function test_detail_qr_is_null_while_listed_or_resold(): void
    {
        $ticket = $this->paidTicket($this->alice);
        $listing = app(ResaleService::class)->list($this->alice, $ticket, 4000);

        $listed = $this->getJson("/api/tickets/{$ticket->id}", $this->h($this->alice))->assertOk();
        $this->assertNull($listed->json('ticket.qr_uuid'));
        $this->assertNull($listed->json('ticket.listing_terms'));
        $this->assertStringNotContainsString($ticket->qr_uuid, $listed->getContent());

        app(ResaleService::class)->purchase($this->bob, $listing);
        $resold = $this->getJson("/api/tickets/{$ticket->id}", $this->h($this->alice))->assertOk();
        $this->assertNull($resold->json('ticket.qr_uuid'));
        $this->assertStringNotContainsString($ticket->qr_uuid, $resold->getContent());

        // 買い手の新しい券は QR が出る
        $newId = ResaleListing::find($listing->id)->new_ticket_id;
        $this->assertNotNull($this->getJson("/api/tickets/{$newId}", $this->h($this->bob))->json('ticket.qr_uuid'));
    }

    public function test_detail_terms_use_season_pass_face_price(): void
    {
        $ticket = $this->passTicket($this->alice);

        $body = $this->getJson("/api/tickets/{$ticket->id}", $this->h($this->alice))->assertOk()->json('ticket');

        $this->assertSame(3750, $body['listing_terms']['face_price']); // 5000 * (1 - 0.25)
        $this->assertSame('season_pass', $body['source']);
    }

    public function test_ticket_model_hides_qr_when_serialized(): void
    {
        $ticket = $this->paidTicket($this->alice);

        $this->assertArrayNotHasKey('qr_uuid', $ticket->toArray());
        $this->assertSame(OrderKind::Purchase, $ticket->orderItems()->first()->order->kind);
    }
}
