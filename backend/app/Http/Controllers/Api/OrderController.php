<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function show(Request $request, int $order): JsonResponse
    {
        $found = Order::where('user_id', $request->user()->id)->findOrFail($order);

        return response()->json(['order' => (new OrderResource($found, CarbonImmutable::now()))->resolve()]);
    }
}
