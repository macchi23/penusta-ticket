<?php

namespace App\Http\Controllers\Api;

use App\Enums\ResaleListingStatus;
use App\Exceptions\ResaleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResaleListingRequest;
use App\Http\Resources\MyListingResource;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Services\ActivityRecorder;
use App\Services\Resale\MyTicketService;
use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketListingController extends Controller
{
    public function __construct(
        private ResaleService $resale,
        private MyTicketService $tickets,
        private ActivityRecorder $recorder,
    ) {}

    public function store(StoreResaleListingRequest $request, int $ticket): JsonResponse
    {
        $user = $request->user();
        // 他人の券は 404(ID の列挙対策)。サービス側の not_owner(403) は最終防衛線
        $item = $this->tickets->ownedItem($user, $ticket);
        abort_if($item === null, 404);

        $listing = $this->resale->list($user, Ticket::findOrFail($ticket), $request->integer('price'));

        $this->recorder->record('resale_list', $request, $user, [
            'listing_id' => $listing->id,
            'match_id' => $listing->match_id,
            'block_id' => $listing->block_id,
            'price' => $listing->price,
            'source' => $item->order->kind->value,
        ], CarbonImmutable::now());

        return response()->json(['listing' => (new MyListingResource($listing))->resolve($request)], 201);
    }

    public function destroy(Request $request, int $ticket): JsonResponse
    {
        $user = $request->user();
        abort_if($this->tickets->ownedItem($user, $ticket) === null, 404);

        $active = ResaleListing::where('ticket_id', $ticket)
            ->where('status', ResaleListingStatus::Active->value)
            ->first() ?? throw ResaleException::listingUnavailable();

        $listing = $this->resale->cancel($user, $active);

        $this->recorder->record('resale_cancel', $request, $user, [
            'listing_id' => $listing->id,
            'match_id' => $listing->match_id,
        ], CarbonImmutable::now());

        return response()->json(['listing' => (new MyListingResource($listing))->resolve($request)]);
    }
}
