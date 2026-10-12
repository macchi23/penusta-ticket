<?php

namespace App\Http\Resources;

use App\Enums\OrderKind;
use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(Order $resource, private CarbonImmutable $now)
    {
        parent::__construct($resource);
    }

    public static function time(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : Carbon::instance($t)->setTimezone('Asia/Tokyo')->toIso8601String();
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'items.ticket.seat',
            'items.ticket.block.stand',
            'items.ticket.block.seatCategory',
            'items.ticketType',
            'items.ticket.resaleListings',
            'resaleListing',
        ]);

        $items = $this->items
            ->filter(fn (OrderItem $i) => in_array($i->ticket->status, [TicketStatus::Paid, TicketStatus::Resold], true)
                || ($i->ticket->status === TicketStatus::Held && $i->ticket->expires_at > $this->now))
            ->sortBy('id')
            ->values();

        // リセール注文は明細(出品価格)に買い手手数料を足した額が支払額。それ以外は 0
        $buyerFee = $this->kind === OrderKind::Resale ? (int) ($this->resaleListing?->buyer_fee ?? 0) : 0;

        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'status' => $this->status->value,
            'kind' => $this->kind->value,
            'expires_at' => self::time($this->expires_at),
            'ordered_at' => self::time($this->ordered_at),
            'total' => (int) $items->sum('price') + $buyerFee,
            'buyer_fee' => $buyerFee,
            'items' => $items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'ticket_id' => $i->ticket_id,
                'block' => [
                    'id' => $i->ticket->block->id,
                    'code' => $i->ticket->block->code,
                    'stand' => $i->ticket->block->stand->name,
                ],
                'seating_type' => $i->ticket->block->seating_type,
                'seat' => $i->ticket->seat === null ? null : [
                    'id' => $i->ticket->seat->id,
                    'row' => $i->ticket->seat->row_label,
                    'number' => $i->ticket->seat->number,
                ],
                'seat_category' => $i->ticket->block->seatCategory->name,
                'ticket_type' => [
                    'id' => $i->ticketType->id,
                    'code' => $i->ticketType->code,
                    'name' => $i->ticketType->name,
                ],
                'price' => $i->price,
                'ticket_status' => $i->ticket->status->value,
                'resale_payout' => $i->ticket->status === TicketStatus::Resold
                    ? $i->ticket->resaleListings->firstWhere('status', ResaleListingStatus::Sold)?->payout
                    : null,
            ])->all(),
        ];
    }
}
