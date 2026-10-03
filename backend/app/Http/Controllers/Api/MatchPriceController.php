<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchPriceResource;
use App\Models\FootballMatch;
use App\Models\MatchPrice;
use Illuminate\Http\JsonResponse;

class MatchPriceController extends Controller
{
    public function index(FootballMatch $match): JsonResponse
    {
        $prices = MatchPrice::with(['seatCategory', 'ticketType'])
            ->where('match_prices.match_id', $match->id)
            ->join('seat_categories', 'seat_categories.id', '=', 'match_prices.seat_category_id')
            ->join('ticket_types', 'ticket_types.id', '=', 'match_prices.ticket_type_id')
            ->orderBy('seat_categories.display_order')
            ->orderBy('seat_categories.id')
            ->orderBy('ticket_types.display_order')
            ->orderBy('ticket_types.id')
            ->select('match_prices.*')
            ->get();

        return response()->json([
            'data' => $prices->map(fn (MatchPrice $p) => (new MatchPriceResource($p))->resolve())->all(),
        ]);
    }
}
