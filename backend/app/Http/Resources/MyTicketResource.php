<?php

namespace App\Http\Resources;

use App\Enums\ResaleListingStatus;
use App\Enums\TicketStatus;
use App\Services\Resale\MyTicketView;
use App\Services\SeatmapBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property MyTicketView $resource
 */
class MyTicketResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(MyTicketView $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $item = $this->resource->item;
        $ticket = $item->ticket;
        $listing = $this->resource->listing;

        return [
            'id' => $ticket->id,
            'match' => app(SeatmapBuilder::class)->formatMatch($ticket->match),
            'block' => [
                'id' => $ticket->block->id,
                'code' => $ticket->block->code,
                'stand' => $ticket->block->stand->name,
            ],
            'seating_type' => $ticket->block->seating_type,
            'seat_category' => $ticket->block->seatCategory->name,
            'seat' => $ticket->seat === null ? null : [
                'id' => $ticket->seat->id,
                'row' => $ticket->seat->row_label,
                'number' => $ticket->seat->number,
            ],
            'ticket_type' => [
                'id' => $item->ticketType->id,
                'code' => $item->ticketType->code,
                'name' => $item->ticketType->name,
            ],
            'price' => $item->price,
            'source' => $item->order->kind->value,
            'status' => $ticket->status->value,
            'order_id' => $item->order_id,
            'listing' => $listing === null ? null : (new MyListingResource($listing))->resolve($request),
            'can_list' => $this->resource->canList,
            'can_cancel' => $this->resource->canCancel,
        ];
    }

    /** QR は paid かつ出品中でないときだけ。出品中は買い手に渡る可能性があるので出さない。 */
    protected function qrUuid(): ?string
    {
        $ticket = $this->resource->item->ticket;

        return $ticket->status === TicketStatus::Paid && $this->resource->listing?->status !== ResaleListingStatus::Active
            ? $ticket->qr_uuid
            : null;
    }
}
