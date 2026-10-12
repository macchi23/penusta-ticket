<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResaleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ResaleHoldResource;
use App\Models\FootballMatch;
use App\Models\ResaleHold;
use App\Models\ResaleListing;
use App\Services\ActivityRecorder;
use App\Services\Resale\ResaleCartService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ResaleCartController extends Controller
{
    public function __construct(
        private ResaleCartService $carts,
        private ActivityRecorder $recorder,
    ) {}

    public function show(Request $request, FootballMatch $match): JsonResponse
    {
        $current = $this->carts->current($request->user(), $match->id);

        return $this->respond($current->hold, $current->now);
    }

    public function hold(Request $request, ResaleListing $listing): JsonResponse
    {
        $user = $request->user();
        $result = $this->carts->hold($user, $listing->id);

        if ($result->created) {
            $this->recorder->record('resale_add_to_cart', $request, $user, [
                ...$this->listingPayload($result->hold),
                'quantity' => 1,
            ], $result->now);
        }

        return $this->respond($result->hold, $result->now, $result->created ? 201 : 200);
    }

    public function release(Request $request, int $hold): Response
    {
        $user = $request->user();
        $result = $this->carts->release($user, $hold);

        if ($result->released) {
            $this->recorder->record('resale_remove_from_cart', $request, $user, [
                'listing_id' => $result->hold->listing_id,
                'hold_id' => $result->hold->id,
                'match_id' => $result->hold->match_id,
                'block_id' => $result->hold->listing->block_id,
                'quantity' => 1,
            ], $result->now);
        }

        return response()->noContent();
    }

    public function checkout(Request $request, int $hold): JsonResponse
    {
        $user = $request->user();

        try {
            $result = $this->carts->checkout($user, $hold);
        } catch (ResaleException $e) {
            if ($e->errorCode === 'payment_failed') {
                // 失敗した購入は巻き戻るので注文は無い。自分の仮押さえから出品を引く
                $held = ResaleHold::whereKey($hold)->where('user_id', $user->id)->first();
                $this->recorder->record('payment_failed', $request, $user, [
                    'source' => 'resale',
                    'match_id' => $held?->match_id,
                    'listing_id' => $held?->listing_id,
                    'hold_id' => $held?->id,
                    'quantity' => 1,
                    'reason' => 'declined',
                ], CarbonImmutable::now());
            }

            throw $e;
        }

        if ($result->created) {
            $order = $result->order;
            $this->recorder->record('resale_purchase', $request, $user, [
                ...$this->listingPayload($result->hold),
                'order_id' => $order->id,
                'total' => $order->total,
            ], $result->now);
        }

        return response()->json(
            ['order' => (new OrderResource($result->order, $result->now))->resolve()],
            $result->created ? 201 : 200,
        );
    }

    private function respond(?ResaleHold $hold, CarbonImmutable $now, int $status = 200): JsonResponse
    {
        return response()->json([
            'hold' => $hold === null ? null : (new ResaleHoldResource($hold))->resolve(),
            'server_time' => OrderResource::time($now),
        ], $status);
    }

    /** @return array<string, mixed> */
    private function listingPayload(ResaleHold $hold): array
    {
        $listing = $hold->listing;

        return [
            'listing_id' => $listing->id,
            'hold_id' => $hold->id,
            'match_id' => $listing->match_id,
            'block_id' => $listing->block_id,
            'price' => $listing->price,
            'discount_rate' => round(1 - $listing->price / $listing->face_price, 3),
        ];
    }
}
