<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetUnreservedQuantitiesRequest;
use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\OrderResource;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\Purchase\CartChange;
use App\Services\Purchase\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private CartService $carts,
        private ActivityRecorder $recorder,
    ) {}

    public function show(Request $request, FootballMatch $match): JsonResponse
    {
        $current = $this->carts->current($request->user(), $match);

        return $this->respond($current->cart, $current->now);
    }

    public function store(StoreCartItemRequest $request, FootballMatch $match): JsonResponse
    {
        $change = $this->carts->addItem(
            $request->user(),
            $match,
            $request->integer('seat_id') ?: null,
            $request->integer('block_id') ?: null,
            $request->integer('ticket_type_id') ?: null,
        );
        if ($change->changed) {
            $this->record('add_to_cart', $request, $match, $change);
        }

        return $this->respond($change->cart, $change->now, $change->changed ? 201 : 200);
    }

    public function setUnreservedQuantities(SetUnreservedQuantitiesRequest $request, FootballMatch $match, Block $block): JsonResponse
    {
        $change = $this->carts->setUnreservedQuantities($request->user(), $match, $block, $request->validated('quantities'));
        if ($change->added > 0) {
            $this->record('add_to_cart', $request, $match, $change, $change->added);
        }
        if ($change->released > 0) {
            $this->record('remove_from_cart', $request, $match, $change, $change->released);
        }

        return $this->respond($change->cart, $change->now);
    }

    public function update(UpdateCartItemRequest $request, FootballMatch $match, int $item): JsonResponse
    {
        $change = $this->carts->changeTicketType($request->user(), $match, $item, $request->integer('ticket_type_id'));

        return $this->respond($change->cart, $change->now);
    }

    public function destroy(Request $request, FootballMatch $match, int $item): JsonResponse
    {
        $change = $this->carts->removeItem($request->user(), $match, $item);
        if ($change->changed) {
            $this->record('remove_from_cart', $request, $match, $change);
        }

        return $this->respond($change->cart, $change->now);
    }

    private function record(string $type, Request $request, FootballMatch $match, CartChange $change, int $quantity = 1): void
    {
        $this->recorder->record($type, $request, $request->user(), [
            'match_id' => $match->id,
            'block_code' => $change->block->code,
            'quantity' => $quantity,
            'order_id' => $change->cart->id,
            'seat_id' => $change->seatId,
        ], $change->now);
    }

    private function respond(?Order $cart, CarbonImmutable $now, int $status = 200): JsonResponse
    {
        return response()->json([
            'cart' => $cart === null ? null : (new OrderResource($cart, $now))->resolve(),
            'server_time' => OrderResource::time($now),
            'max_items' => (int) config('ticketing.max_tickets_per_order'),
        ], $status);
    }
}
