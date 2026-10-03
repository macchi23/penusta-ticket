<?php

namespace App\Http\Resources;

use App\Models\MatchPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchPrice */
class MatchPriceResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'seat_category' => $this->seatCategory->name,
            'ticket_type' => [
                'id' => $this->ticketType->id,
                'code' => $this->ticketType->code,
                'name' => $this->ticketType->name,
            ],
            'price' => $this->price,
        ];
    }
}
