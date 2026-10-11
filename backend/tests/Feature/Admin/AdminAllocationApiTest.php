<?php

namespace Tests\Feature\Admin;

use App\Exceptions\AllocationChangeException;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchBlockAllocation;
use App\Models\MatchBlockAllocationChange;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Admin\AllocationChangeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

/**
 * AllocationChangeService は最上位トランザクションを要求するため、RefreshDatabase（テスト全体をトランザクションで包む）は使えない。
 * 終了時に全テーブルを空にする。
 */
class AdminAllocationApiTest extends TestCase
{
    use BuildsSaleFixture;
    use DatabaseTruncation;

    private FootballMatch $match;

    private Block $reserved;

    private Block $free;

    private Block $other;

    private TicketType $adult;

    private User $admin;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ticketing.demo_auth_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));

        $this->buildSaleFixture();
        $this->reserved = $this->makeBlock('M1', 'reserved', 10);
        $this->free = $this->makeBlock('F1', 'unreserved', 5);
        $this->other = $this->makeBlock('M2', 'reserved', 10);
        Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => 1]);
        Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => 2]);
        Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'A', 'number' => 3]);

        $this->buildSalePricesAndUser();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function as(User $user): array
    {
        return ['X-Demo-User-Id' => (string) $user->id];
    }

    private function url(?FootballMatch $match = null): string
    {
        return '/api/admin/matches/'.($match ?? $this->match)->id.'/allocations';
    }

    private function change(Block $block, string $expected, string $allocation): array
    {
        return ['block_id' => $block->id, 'expected' => $expected, 'allocation' => $allocation];
    }

    private function makeTicket(Block $block, string $status, ?CarbonImmutable $expiresAt = null, ?int $seatNumber = null): void
    {
        Ticket::create([
            'match_id' => $this->match->id, 'block_id' => $block->id,
            'seat_id' => $seatNumber ? Seat::where('block_id', $block->id)->where('number', $seatNumber)->value('id') : null,
            'status' => $status, 'qr_uuid' => (string) Str::uuid(), 'expires_at' => $expiresAt,
        ]);
    }

    private function doPatch(array $changes)
    {
        return $this->patchJson($this->url(), ['changes' => $changes], $this->as($this->admin));
    }

    public function test_requires_authentication_and_admin_role(): void
    {
        $this->getJson('/api/admin/matches')->assertStatus(401);
        $this->getJson($this->url())->assertStatus(401);
        $this->patchJson($this->url(), ['changes' => [$this->change($this->free, 'home', 'buffer')]])->assertStatus(401);

        $this->getJson('/api/admin/matches', $this->as($this->alice))->assertStatus(403);
        $this->getJson($this->url(), $this->as($this->alice))->assertStatus(403);
        $this->patchJson($this->url(), ['changes' => [$this->change($this->free, 'home', 'buffer')]], $this->as($this->alice))
            ->assertStatus(403);
        $this->assertSame('home', MatchBlockAllocation::where('block_id', $this->free->id)->value('allocation'));
    }

    public function test_is_401_when_demo_flag_is_off(): void
    {
        config(['ticketing.demo_auth_enabled' => false]);

        $this->getJson('/api/admin/matches', $this->as($this->admin))->assertStatus(401);
        $this->getJson($this->url(), $this->as($this->admin))->assertStatus(401);
    }

    public function test_matches_index_reports_editable(): void
    {
        $past = FootballMatch::create([
            'season_id' => $this->match->season_id, 'home_team_id' => $this->match->home_team_id,
            'away_team_id' => $this->match->away_team_id, 'stadium_id' => $this->match->stadium_id,
            'kickoff_at' => CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC'), 'status' => 'finished', 'section_label' => 'B',
        ]);

        $res = $this->getJson('/api/admin/matches', $this->as($this->admin))->assertOk();
        $byId = collect($res->json('data'))->keyBy('id');

        $this->assertTrue($byId[$this->match->id]['editable']);
        $this->assertFalse($byId[$past->id]['editable']);
        $this->assertSame('Test Stadium', $byId[$this->match->id]['stadium']);
        $this->assertArrayHasKey('home_team', $byId[$this->match->id]);
    }

    public function test_show_returns_blocks_with_ticket_counts_and_locked(): void
    {
        $now = CarbonImmutable::now();
        $this->makeTicket($this->reserved, 'paid', null, 1);
        $this->makeTicket($this->reserved, 'held', $now->addMinutes(10), 2);
        $this->makeTicket($this->reserved, 'held', $now->subMinute(), 3);
        $this->makeTicket($this->free, 'held', $now->subMinute());

        $res = $this->getJson($this->url(), $this->as($this->admin))->assertOk();

        $this->assertTrue($res->json('editable'));
        $this->assertSame($this->match->id, $res->json('match.id'));
        $this->assertSame(['width' => 1000, 'height' => 760], $res->json('viewbox'));
        $blocks = collect($res->json('blocks'))->keyBy('id');
        $this->assertCount(3, $blocks);
        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'stand', 'seating_type', 'seat_category', 'capacity', 'geometry', 'allocation', 'tickets', 'locked'],
            array_keys($blocks[$this->reserved->id]),
        );
        $this->assertSame(['paid' => 1, 'held' => 1], $blocks[$this->reserved->id]['tickets']);
        $this->assertTrue($blocks[$this->reserved->id]['locked']);
        $this->assertSame(['paid' => 0, 'held' => 0], $blocks[$this->free->id]['tickets']);
        $this->assertFalse($blocks[$this->free->id]['locked']);
        $this->assertFalse($blocks[$this->other->id]['locked']);
    }

    public function test_update_applies_changes_and_is_visible_to_public_seatmap_and_cart(): void
    {
        $res = $this->doPatch([$this->change($this->free, 'home', 'buffer'), $this->change($this->other, 'home', 'closed')])
            ->assertOk();

        $changeSetId = $res->json('change_set_id');
        $this->assertIsString($changeSetId);
        $blocks = collect($res->json('blocks'))->keyBy('id');
        $this->assertSame('buffer', $blocks[$this->free->id]['allocation']);
        $this->assertSame('closed', $blocks[$this->other->id]['allocation']);
        $this->assertSame('home', $blocks[$this->reserved->id]['allocation']);
        $this->assertTrue($res->json('editable'));

        $this->assertSame('buffer', MatchBlockAllocation::where('block_id', $this->free->id)->value('allocation'));
        $rows = MatchBlockAllocationChange::all();
        $this->assertCount(2, $rows);
        $this->assertSame([$changeSetId], $rows->pluck('change_set_id')->unique()->all());
        $this->assertSame([$this->admin->id], $rows->pluck('changed_by')->unique()->all());

        $this->app['auth']->forgetGuards();
        $seatmap = collect($this->getJson("/api/matches/{$this->match->id}/seatmap", $this->as($this->alice))->assertOk()->json('blocks'))->keyBy('id');
        $this->assertSame('buffer', $seatmap[$this->free->id]['allocation']);
        $this->assertSame(0, $seatmap[$this->free->id]['available']);
        $this->assertSame('closed', $seatmap[$this->other->id]['allocation']);
        $this->assertSame(0, $seatmap[$this->other->id]['available']);
        $this->assertSame(10, $seatmap[$this->reserved->id]['available']);

        $this->postJson("/api/matches/{$this->match->id}/cart/items", ['block_id' => $this->free->id], $this->as($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
    }

    public function test_update_conflict_lists_stale_and_has_tickets(): void
    {
        $this->makeTicket($this->reserved, 'paid', null, 1);

        $res = $this->doPatch([
            $this->change($this->reserved, 'home', 'buffer'),
            $this->change($this->free, 'away', 'buffer'),
            $this->change($this->other, 'home', 'buffer'),
        ])->assertStatus(409)->assertJsonPath('code', 'allocation_conflict');

        $blocks = collect($res->json('details.blocks'))->keyBy('block_id');
        $this->assertCount(2, $blocks);
        $this->assertSame(
            ['block_id' => $this->reserved->id, 'reason' => 'has_tickets', 'current' => 'home', 'tickets' => ['paid' => 1, 'held' => 0]],
            $blocks[$this->reserved->id],
        );
        $this->assertSame(
            ['block_id' => $this->free->id, 'reason' => 'stale', 'current' => 'home', 'tickets' => ['paid' => 0, 'held' => 0]],
            $blocks[$this->free->id],
        );
        $this->assertSame(0, MatchBlockAllocationChange::count());
    }

    public function test_update_is_match_locked_after_kickoff(): void
    {
        $this->travelTo($this->match->kickoff_at->addMinute());

        $this->doPatch([$this->change($this->free, 'home', 'buffer')])
            ->assertStatus(409)->assertJsonPath('code', 'match_locked')->assertJsonMissingPath('details');
        $this->getJson($this->url(), $this->as($this->admin))->assertOk()->assertJsonPath('editable', false);
    }

    public function test_non_numeric_ids_are_404(): void
    {
        $this->getJson('/api/admin/matches/abc/allocations', $this->as($this->admin))->assertStatus(404);
        $this->getJson('/api/matches/abc/seatmap')->assertStatus(404);
    }

    public function test_update_is_throttled_per_user(): void
    {
        config(['ticketing.admin_update_rate_per_minute' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->doPatch([])->assertStatus(422);
        }
        $this->doPatch([])->assertStatus(429);
    }

    public function test_update_is_busy_when_service_reports_busy(): void
    {
        $this->mock(AllocationChangeService::class, fn ($m) => $m->shouldReceive('apply')->andThrow(AllocationChangeException::busy()));

        $this->doPatch([$this->change($this->free, 'home', 'buffer')])
            ->assertStatus(409)->assertJsonPath('code', 'allocation_busy');
    }

    public function test_validation_failures_are_422(): void
    {
        $other = FootballMatch::create([
            'season_id' => $this->match->season_id, 'home_team_id' => $this->match->home_team_id,
            'away_team_id' => $this->match->away_team_id, 'stadium_id' => $this->match->stadium_id,
            'kickoff_at' => CarbonImmutable::parse('2030-04-01 10:00:00', 'UTC'), 'status' => 'on_sale', 'section_label' => 'C',
        ]);
        MatchBlockAllocation::where('block_id', $this->other->id)->delete();
        $unknownBlock = $this->other;

        $cases = [
            'empty' => [],
            'duplicate' => [$this->change($this->free, 'home', 'buffer'), $this->change($this->free, 'home', 'closed')],
            'bad enum' => [$this->change($this->free, 'home', 'vip')],
            'bad expected' => [$this->change($this->free, 'nope', 'buffer')],
            'no-op' => [$this->change($this->free, 'home', 'home')],
            'missing allocation' => [['block_id' => $this->free->id, 'expected' => 'home']],
            'no allocation row' => [$this->change($unknownBlock, 'home', 'buffer')],
        ];
        foreach ($cases as $name => $changes) {
            $this->doPatch($changes)->assertStatus(422);
        }
        $this->patchJson($this->url(), [], $this->as($this->admin))->assertStatus(422);
        $this->patchJson($this->url($other), ['changes' => [$this->change($this->free, 'home', 'buffer')]], $this->as($this->admin))
            ->assertStatus(422)->assertJsonMissingPath('exception');

        $this->assertSame(0, MatchBlockAllocationChange::count());
        $this->assertSame('home', MatchBlockAllocation::where('block_id', $this->free->id)->value('allocation'));
    }
}
