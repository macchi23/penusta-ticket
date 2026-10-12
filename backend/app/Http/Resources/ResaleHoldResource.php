<?php

namespace App\Http\Resources;

use App\Models\ResaleHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 買い手本人の仮押さえ。ticket_id・出品者の情報は出さない。
 *
 * @mixin ResaleHold
 */
class ResaleHoldResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(ResaleHold $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['listing.ticket.seat', 'listing.block.stand', 'listing.block.seatCategory', 'listing.ticketType']);
        $l = $this->listing;

        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'match_id' => $this->match_id,
            'expires_at' => OrderResource::time($this->expires_at),
            'block' => ['id' => $l->block->id, 'code' => $l->block->code, 'stand' => $l->block->stand->name],
            'seat' => $l->ticket->seat === null ? null : [
                'id' => $l->ticket->seat->id,
                'row' => $l->ticket->seat->row_label,
                'number' => $l->ticket->seat->number,
            ],
            'seat_category' => $l->block->seatCategory->name,
            'ticket_type' => [
                'id' => $l->ticketType->id,
                'code' => $l->ticketType->code,
                'name' => $l->ticketType->name,
            ],
            'price' => $l->price,
            'buyer_fee' => $l->buyer_fee,
            'total' => $l->price + $l->buyer_fee,
        ];
    }
}
