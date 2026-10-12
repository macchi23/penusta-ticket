<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResaleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\ResaleListing;
use App\Services\ActivityRecorder;
use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResalePurchaseController extends Controller
{
    public function __construct(
        private ResaleService $resale,
        private ActivityRecorder $recorder,
    ) {}

    public function store(Request $request, ResaleListing $listing): JsonResponse
    {
        $user = $request->user();

        try {
            $sold = $this->resale->purchase($user, $listing);
        } catch (ResaleException $e) {
            if ($e->errorCode === 'payment_failed') {
                // 失敗した購入は巻き戻るので注文は無い。出品単位で記録する
                $this->recorder->record('payment_failed', $request, $user, [
                    'source' => 'resale',
                    'match_id' => $listing->match_id,
                    'listing_id' => $listing->id,
                    'quantity' => 1,
                    'reason' => 'declined',
                ], CarbonImmutable::now());
            }

            throw $e;
        }

        $order = $sold->buyerOrder;
        $this->recorder->record('resale_purchase', $request, $user, [
            'listing_id' => $sold->id,
            'order_id' => $order->id,
            'match_id' => $sold->match_id,
            'block_id' => $sold->block_id,
            'price' => $sold->price,
            'total' => $order->total,
            'discount_rate' => round(1 - $sold->price / $sold->face_price, 3),
        ], CarbonImmutable::now());

        return response()->json(['order' => (new OrderResource($order, CarbonImmutable::now()))->resolve()], 201);
    }
}
