<?php

namespace Tests\Feature;

use App\Enums\MembershipKind;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchPrice;
use App\Models\Membership;
use App\Models\Order;
use App\Models\SalePhase;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Purchase\SaleMethodRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class SaleGateTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    private FootballMatch $match;

    private TicketType $adult;

    private User $alice;

    private Block $free;

    private SalePhase $presale;

    private SalePhase $general;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ticketing.demo_auth_enabled' => true, 'ticketing.hold_minutes' => 100000]);
        $this->buildSaleFixture();
        $this->buildSalePricesAndUser();
        $this->free = $this->makeBlock('F1', 'unreserved', 10);

        // 先行 2026-06-01 00:00Z 〜 06-02 00:00Z、一般 06-02 00:00Z 〜 kickoff
        $this->match->salePhases()->delete();
        $this->presale = $this->phase('fanclub_presale', '2026-06-01 00:00:00', '2026-06-02 00:00:00', MembershipKind::Fanclub->value);
        $this->general = $this->phase('general', '2026-06-02 00:00:00', '2030-03-01 10:00:00');
    }

    private function phase(string $kind, string $start, string $end, ?string $eligibility = null): SalePhase
    {
        return SalePhase::create([
            'match_id' => $this->match->id, 'kind' => $kind, 'eligibility' => $eligibility,
            'starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($end, 'UTC'),
        ]);
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function member(User $user, string $from, string $until, string $kind = 'fanclub'): void
    {
        Membership::create(['user_id' => $user->id, 'kind' => $kind, 'valid_from' => $from, 'valid_until' => $until]);
    }

    private function h(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['X-Demo-User-Id' => (string) $user->id];
    }

    private function hold(User $user)
    {
        return $this->postJson("/api/matches/{$this->match->id}/cart/items", ['block_id' => $this->free->id], $this->h($user));
    }

    private function setQty(User $user, int $qty)
    {
        return $this->putJson("/api/matches/{$this->match->id}/cart/blocks/{$this->free->id}/items", [
            'quantities' => [['ticket_type_id' => $this->adult->id, 'quantity' => $qty]],
        ], $this->h($user));
    }

    private function checkout(User $user, Order $order)
    {
        return $this->postJson("/api/orders/{$order->id}/checkout", [], $this->h($user));
    }

    public function test_phase_boundaries(): void
    {
        $this->general->update(['starts_at' => CarbonImmutable::parse('2026-06-02 00:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-06-03 00:00:00', 'UTC')]);

        $this->at('2026-05-31 23:59:59');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');

        $this->member($this->alice, '2026-01-01', '2026-12-31');
        $this->at('2026-06-01 00:00:00');
        $this->hold($this->alice)->assertCreated();

        $bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
        $this->at('2026-06-02 23:59:59');
        $this->hold($bob)->assertCreated();

        $this->at('2026-06-03 00:00:00');
        $carol = User::create(['name' => 'Carol', 'email' => 'c@example.com', 'password' => 'x']);
        $this->hold($carol)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
    }

    public function test_presale_requires_fanclub_membership(): void
    {
        $this->at('2026-06-01 03:00:00');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_eligible');
        $this->setQty($this->alice, 1)->assertStatus(422)->assertJsonPath('code', 'not_eligible');
        $this->assertSame(0, Order::count());

        $this->member($this->alice, '2026-01-01', '2026-12-31', 'season_pass');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_eligible');

        $this->member($this->alice, '2026-01-01', '2026-12-31');
        $this->hold($this->alice)->assertCreated();
    }

    public function test_expired_member_is_not_eligible(): void
    {
        $this->member($this->alice, '2025-01-01', '2026-05-31');
        $this->at('2026-06-01 03:00:00');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_eligible');
    }

    public function test_jst_boundaries(): void
    {
        // valid_from = 2026-06-01 (JST 0:00 = 05-31 15:00Z) に合わせて先行を前倒しする
        $this->presale->update(['starts_at' => CarbonImmutable::parse('2026-05-30 00:00:00', 'UTC')]);
        $this->member($this->alice, '2026-06-01', '2026-06-01');

        $this->at('2026-05-31 14:59:59');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_eligible');

        $this->at('2026-05-31 15:00:00');
        $this->hold($this->alice)->assertCreated();

        // valid_until 当日の JST 23:59:59 = 06-01 14:59:59Z
        $this->at('2026-06-01 14:59:59');
        $this->hold($this->alice)->assertCreated();

        $this->at('2026-06-01 15:00:00');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_eligible');
    }

    public function test_checkout_across_phases_records_general_phase(): void
    {
        $this->member($this->alice, '2026-01-01', '2026-12-31');
        $this->at('2026-06-01 12:00:00');
        $this->hold($this->alice)->assertCreated();
        $order = Order::firstOrFail();

        $this->at('2026-06-02 00:00:01');
        $this->checkout($this->alice, $order)->assertOk();

        $this->assertSame($this->general->id, $order->fresh()->sale_phase_id);
    }

    public function test_checkout_in_presale_records_presale_phase_and_rejects_ineligible(): void
    {
        $this->at('2026-06-01 12:00:00');
        // 先行外で hold した状況を作る（一般フェーズ扱いで保持 → 先行へ切替）
        $this->presale->update(['eligibility' => null]);
        $this->hold($this->alice)->assertCreated();
        $order = Order::firstOrFail();
        $this->presale->update(['eligibility' => 'fanclub']);

        $this->checkout($this->alice, $order)->assertStatus(422)->assertJsonPath('code', 'not_eligible');
        $this->assertSame('pending', $order->fresh()->status->value);

        $this->member($this->alice, '2026-01-01', '2026-12-31');
        $this->checkout($this->alice, $order)->assertOk();
        $this->assertSame($this->presale->id, $order->fresh()->sale_phase_id);
    }

    public function test_ineligible_cannot_hold_in_presale_and_checkout_after_kickoff_is_not_on_sale(): void
    {
        $this->at('2026-06-01 12:00:00');
        $this->hold($this->alice)->assertStatus(422)->assertJsonPath('code', 'not_eligible');

        $this->general->update(['ends_at' => CarbonImmutable::parse('2026-06-02 12:00:00', 'UTC')]);
        $this->at('2026-06-02 11:00:00');
        $this->hold($this->alice)->assertCreated();
        $order = Order::firstOrFail();

        $this->at('2026-06-02 12:00:00');
        $this->checkout($this->alice, $order)->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
    }

    public function test_decrease_only_works_without_eligibility(): void
    {
        $this->at('2026-06-02 12:00:00');
        $this->setQty($this->alice, 3)->assertOk();

        $this->at('2026-06-02 13:00:00');
        $this->general->update(['eligibility' => 'fanclub']);
        $this->setQty($this->alice, 1)->assertOk();
        $this->setQty($this->alice, 2)->assertStatus(422)->assertJsonPath('code', 'not_eligible');
        $this->setQty($this->alice, 0)->assertOk();
    }

    public function test_change_ticket_type_requires_eligibility_and_open_phase(): void
    {
        $child = TicketType::create(['name' => '子供', 'code' => 'child', 'display_order' => 2]);
        MatchPrice::create(['match_id' => $this->match->id, 'seat_category_id' => $this->category->id,
            'ticket_type_id' => $child->id, 'price' => 2500]);

        $this->at('2026-06-02 12:00:00');
        $itemId = $this->hold($this->alice)->assertCreated()->json('cart.items.0.id');
        $url = "/api/matches/{$this->match->id}/cart/items/{$itemId}";

        $this->general->update(['eligibility' => 'fanclub']);
        $this->patchJson($url, ['ticket_type_id' => $child->id], $this->h($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'not_eligible');

        $this->general->update(['eligibility' => null, 'ends_at' => CarbonImmutable::parse('2026-06-02 12:00:00', 'UTC')]);
        $this->patchJson($url, ['ticket_type_id' => $child->id], $this->h($this->alice))
            ->assertStatus(422)->assertJsonPath('code', 'not_on_sale');
    }

    public function test_method_check_constraint_rejects_unknown_method(): void
    {
        $this->expectException(QueryException::class);
        $this->general->update(['method' => 'lottery']);
    }

    public function test_unknown_method_throws(): void
    {
        $this->expectException(RuntimeException::class);
        app(SaleMethodRegistry::class)->for('lottery');
    }
}
