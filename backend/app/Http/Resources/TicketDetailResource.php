<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TicketDetailResource extends MyTicketResource
{
    public function toArray(Request $request): array
    {
        $face = $this->resource->face;

        return parent::toArray($request) + [
            'qr_uuid' => $this->qrUuid(),
            'listing_terms' => $this->resource->canList && $face !== null ? [
                'face_price' => $face[0],
                'min_price' => (int) config('ticketing.resale.min_price'),
                'seller_fee_rate' => (float) config('ticketing.resale.seller_fee_rate'),
                'transfer_fee' => (int) config('ticketing.resale.transfer_fee'),
                'buyer_fee' => (int) config('ticketing.resale.buyer_fee'),
            ] : null,
        ];
    }
}
