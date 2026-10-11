<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Membership;
use App\Models\SalePhase;
use App\Models\Seat;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class SeatVisibilityTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private TicketType $adult;

    private User $alice;

    private User $member;

    private Block $reserved;

    private Block $free;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ticketing.demo_auth_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));
        $this->buildSaleFixture();
        $this->buildSalePricesAndUser();
        $this->reserved = $this->makeBlock('R1', 'reserved', 3);
        $this->free = $this->makeBlock('F1', 'unreserved', 10);
        foreach ([1, 2, 3] as $n) {
            Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => $n]);
        }
        $this->member = User::create(['name' => 'Member', 'email' => 'm@example.com', 'password' => 'x']);
        Membership::create(['user_id' => $this->member->id, 'kind' => 'fanclub', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
    }

    private function as(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['X-Demo-User-Id' => (string) $user->id];
    }

    private function seatmapAvailable(array $headers): array
    {
        $this->app['auth']->forgetGuards();
        $blocks = collect($this->getJson("/api/matches/{$this->match->id}/seatmap", $headers)->assertOk()->json('blocks'))->keyBy('id');

        return [$blocks[$this->reserved->id]['available'], $blocks[$this->free->id]['available']];
    }

    private function seats(array $headers)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/matches/{$this->match->id}/blocks/{$this->reserved->id}/seats", $headers);
    }

    private function assertVisible(array $headers): void
    {
        $this->assertSame([3, 10], $this->seatmapAvailable($headers));
        $this->seats($headers)->assertOk()->assertJsonCount(3, 'rows.0.seats');
    }

    private function assertHidden(array $headers): void
    {
        $this->assertSame([null, null], $this->seatmapAvailable($headers));
        $this->seats($headers)->assertForbidden()->assertJsonPath('code', 'seats_hidden')->assertJsonMissingPath('rows');
    }

    public function test_general_sale_is_visible_to_everyone_logged_in_but_not_guests(): void
    {
        $this->assertVisible($this->as($this->alice));
        $this->assertHidden([]);
    }

    public function test_fanclub_presale_shows_seats_only_to_members(): void
    {
        $this->makeSalePhases('presale');

        $this->assertVisible($this->as($this->member));
        $this->assertHidden($this->as($this->alice));
        $this->assertHidden([]);
    }

    public function test_before_sale_hides_seats_even_from_members(): void
    {
        $this->makeSalePhases('before');

        $this->assertHidden($this->as($this->member));
        $this->assertHidden($this->as($this->alice));
    }

    public function test_after_sale_hides_seats(): void
    {
        $this->match->salePhases()->delete();
        SalePhase::create([
            'match_id' => $this->match->id, 'kind' => 'general', 'eligibility' => null,
            'starts_at' => CarbonImmutable::parse('2026-05-01', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-05-20', 'UTC'),
        ]);

        $this->assertHidden($this->as($this->member));
    }

    public function test_non_on_sale_match_hides_seats_even_within_phase(): void
    {
        $this->match->update(['status' => 'unsold']);

        $this->assertHidden($this->as($this->alice));
    }

    public function test_block_allocation_colors_stay_visible_when_hidden(): void
    {
        $this->makeSalePhases('before');

        $blocks = $this->getJson("/api/matches/{$this->match->id}/seatmap")->assertOk()->json('blocks');
        $this->assertSame(['home', 'home'], array_column($blocks, 'allocation'));
    }

    public function test_admin_api_is_unaffected(): void
    {
        $this->makeSalePhases('before');
        $admin = User::factory()->admin()->create();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/matches/{$this->match->id}/allocations", $this->as($admin))->assertOk();
    }
}
