<?php

namespace Tests\Concerns;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Models\Block;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResaleListing;
use App\Models\SeasonPassSeat;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Resale\ResaleCartService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * リセール API テスト共通。BuildsSaleFixture と併用し、使うクラスは $match / $adult / $alice を宣言すること。
 * 現在時刻は 2030-02-01、キックオフは 2030-03-01 10:00 UTC(締切は 150 分前)。
 */
trait BuildsResaleTickets
{
    private Block $reserved;

    private Block $free;

    private User $bob;

    private int $seatNo = 0;

    private function buildResaleFixture(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-02-01 00:00:00', 'UTC'));
        $this->buildSaleFixture();
        $this->reserved = $this->makeBlock('M1', 'reserved', 20);
        $this->free = $this->makeBlock('F1', 'unreserved', 5);
        $this->buildSalePricesAndUser();
        $this->bob = User::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'x']);
    }

    private function h(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['X-Demo-User-Id' => (string) $user->id];
    }

    private function paidTicket(User $owner, ?Block $block = null, int $price = 5000, OrderKind $kind = OrderKind::Purchase, ?int $passSeatId = null, ?int $matchId = null): Ticket
    {
        $block ??= $this->reserved;
        $matchId ??= $this->match->id;
        $seatId = $block->seating_type === 'reserved'
            ? Seat::create(['block_id' => $block->id, 'row_label' => 'A', 'number' => ++$this->seatNo])->id
            : null;

        return $this->ticketAt($owner, $block, $seatId, $price, $kind, $this->adult->id, $passSeatId, TicketStatus::Paid, $matchId);
    }

    private function ticketAt(User $owner, Block $block, ?int $seatId, int $price, OrderKind $kind, int $typeId, ?int $passSeatId, TicketStatus $status, ?int $matchId = null): Ticket
    {
        $matchId ??= $this->match->id;
        $order = Order::create([
            'user_id' => $owner->id, 'match_id' => $matchId, 'status' => OrderStatus::Paid, 'kind' => $kind,
            'total' => $price, 'channel' => 'web', 'ordered_at' => CarbonImmutable::now('UTC'),
        ]);
        $ticket = Ticket::create([
            'match_id' => $matchId, 'block_id' => $block->id, 'seat_id' => $seatId,
            'season_pass_seat_id' => $passSeatId, 'status' => $status, 'qr_uuid' => (string) Str::uuid(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'ticket_id' => $ticket->id, 'ticket_type_id' => $typeId, 'price' => $price]);

        return $ticket;
    }

    /** 仮押さえ → 決済で出品を買う（サービス層）。購入済みの出品を返す。 */
    private function buyViaCart(User $buyer, ResaleListing $listing): ResaleListing
    {
        $cart = app(ResaleCartService::class);
        $cart->checkout($buyer, $cart->hold($buyer, $listing->id)->hold->id);

        return $listing->fresh();
    }

    private function passTicket(User $owner): Ticket
    {
        $pass = TicketType::firstOrCreate(['code' => 'season_pass'], ['name' => '年パス', 'display_order' => 3]);
        $seat = Seat::create(['block_id' => $this->reserved->id, 'row_label' => 'P', 'number' => ++$this->seatNo]);
        $row = SeasonPassSeat::create([
            'season_id' => $this->match->season_id, 'user_id' => $owner->id,
            'block_id' => $this->reserved->id, 'seat_id' => $seat->id,
        ]);

        return $this->ticketAt($owner, $this->reserved, $seat->id, 0, OrderKind::SeasonPass, $pass->id, $row->id, TicketStatus::Paid);
    }
}
