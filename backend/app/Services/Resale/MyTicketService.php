<?php

namespace App\Services\Resale;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Models\MatchPrice;
use App\Models\OrderItem;
use App\Models\ResaleListing;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ログインユーザーの券（paid / resold）の読み取り。他人の券は存在しないものとして扱う。
 */
class MyTicketService
{
    public function __construct(
        private ResaleService $resale,
        private ResaleWindow $window,
    ) {}

    /**
     * @param  'upcoming'|'past'  $scope
     * @return Collection<int, MyTicketView>
     */
    public function list(User $user, string $scope): Collection
    {
        $now = CarbonImmutable::now();
        $upcoming = $scope === 'upcoming';

        $items = $this->query($user)
            ->join('matches', 'matches.id', '=', 'tickets.match_id')
            ->where('matches.kickoff_at', $upcoming ? '>' : '<=', $now)
            ->orderBy('matches.kickoff_at', $upcoming ? 'asc' : 'desc')
            ->orderBy('order_items.id')
            ->get();

        return $this->views($user, $items, $now);
    }

    public function find(User $user, int $ticketId): ?MyTicketView
    {
        $item = $this->query($user)->where('order_items.ticket_id', $ticketId)->first();

        return $item === null ? null : $this->views($user, collect([$item]), CarbonImmutable::now())->first();
    }

    /** 持ち主の明細（出品の source 判定用）。持ち主でなければ null。 */
    public function ownedItem(User $user, int $ticketId): ?OrderItem
    {
        return $this->query($user)->where('order_items.ticket_id', $ticketId)->first();
    }

    /** @return Builder<OrderItem> */
    private function query(User $user): Builder
    {
        return OrderItem::query()
            ->select('order_items.*')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('tickets', 'tickets.id', '=', 'order_items.ticket_id')
            ->where('orders.user_id', $user->id)
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereIn('tickets.status', [TicketStatus::Paid->value, TicketStatus::Resold->value])
            ->with([
                'order',
                'ticketType',
                'ticket.seat',
                'ticket.block.stand',
                'ticket.block.seatCategory',
                'ticket.match.homeTeam',
                'ticket.match.awayTeam',
                'ticket.match.stadium',
                'ticket.match.salePhases',
            ]);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<int, MyTicketView>
     */
    private function views(User $user, Collection $items, CarbonImmutable $now): Collection
    {
        $listings = ResaleListing::whereIn('ticket_id', $items->pluck('ticket_id'))
            ->whereIn('status', [ResaleListingStatus::Active->value, ResaleListingStatus::Sold->value])
            ->get()
            ->keyBy('ticket_id');

        // 年パス券の定価は単券の大人価格から出すので、必要なときだけ一括で引く
        $adultId = null;
        $singles = collect();
        if ($items->contains(fn (OrderItem $i) => $i->order->kind === OrderKind::SeasonPass)) {
            $adultId = TicketType::where('code', 'adult')->value('id');
            $singles = MatchPrice::whereIn('match_id', $items->pluck('ticket.match_id')->unique())
                ->where('ticket_type_id', $adultId)
                ->get()
                ->keyBy(fn (MatchPrice $p) => "{$p->match_id}:{$p->seat_category_id}");
        }

        $min = (int) config('ticketing.resale.min_price');
        $open = [];

        return $items->map(function (OrderItem $item) use ($user, $now, $listings, $adultId, $singles, $min, &$open) {
            $ticket = $item->ticket;
            $listing = $listings->get($ticket->id);
            $hasActive = $listing?->status === ResaleListingStatus::Active;
            $windowOpen = $open[$ticket->match_id] ??= $this->window->isOpen($ticket->match, $now);

            $face = null;
            if ($this->resale->listBlocker($user, $ticket, $item->order, $hasActive, $windowOpen) === null) {
                $single = $singles->get("{$ticket->match_id}:{$ticket->block->seat_category_id}")?->price;
                $face = $this->resale->listingFacePrice($item->order->kind, $item->price, $item->ticket_type_id, $single, $adultId);
                if ($face !== null && $face[0] < $min) {
                    $face = null;
                }
            }

            return new MyTicketView($item, $listing, $face !== null, $listing !== null && $this->resale->canCancel($listing->status), $face);
        })->values();
    }
}
