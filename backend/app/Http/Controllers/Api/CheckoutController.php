<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\Purchase\CheckoutService;
use App\Services\Purchase\PaymentDeclined;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private CheckoutService $checkout,
        private ActivityRecorder $recorder,
    ) {}

    public function store(Request $request, int $order): JsonResponse
    {
        try {
            $result = $this->checkout->checkout($request->user(), $order);
        } catch (PaymentDeclined $e) {
            $this->recorder->record('payment_failed', $request, $request->user(), [
                'match_id' => $e->matchId,
                'order_id' => $e->orderId,
                'quantity' => $e->quantity,
                'reason' => $e->reason,
            ], $e->now);

            throw PurchaseException::paymentDeclined();
        }

        if ($result->purchased) {
            $this->recorder->record('purchase', $request, $request->user(), [
                'match_id' => $result->order->match_id,
                'order_id' => $result->order->id,
                'quantity' => $result->quantity,
            ], $result->now);
        }

        return response()->json(['order' => (new OrderResource(Order::findOrFail($result->order->id), $result->now))->resolve()]);
    }
}
