<?php

namespace App\Http\Resources;

use App\Models\ResaleListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 他人に見せるリセール一覧。出品者・受取額・出品者手数料・ticket_id・qr_uuid は出さない。
 * ResaleBoardService::board() の戻り値を受ける。
 */
class ResaleBoardResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $b = $this->resource;

        $blocks = $b['listings']
            ->groupBy('block_id')
            ->map(function ($group) {
                $offers = $group
                    ->map(fn (ResaleListing $l) => [
                        'id' => $l->id,
                        'seat' => $l->ticket->seat === null ? null : [
                            'id' => $l->ticket->seat->id,
                            'row' => $l->ticket->seat->row_label,
                            'number' => $l->ticket->seat->number,
                        ],
                        'ticket_type' => [
                            'id' => $l->ticketType->id,
                            'code' => $l->ticketType->code,
                            'name' => $l->ticketType->name,
                        ],
                        'price' => $l->price,
                        'buyer_fee' => $l->buyer_fee,
                        'total' => $l->price + $l->buyer_fee,
                    ])
                    ->sortBy([['total', 'asc'], ['id', 'asc']])
                    ->values();
                $block = $group->first()->block;

                return [
                    'block' => ['id' => $block->id, 'code' => $block->code, 'stand' => $block->stand->name],
                    'seating_type' => $block->seating_type,
                    'seat_category' => $block->seatCategory->name,
                    'count' => $offers->count(),
                    'min_total' => $offers->first()['total'],
                    'listings' => $offers->all(),
                ];
            })
            ->sortBy(fn (array $row) => $row['block']['id'])
            ->values();

        return [
            'status' => $b['status'],
            'opens_at' => OrderResource::time($b['opens_at']),
            'closes_at' => OrderResource::time($b['closes_at']),
            'blocks' => $blocks->all(),
        ];
    }
}
