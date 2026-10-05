<?php

namespace App\Http\Controllers\Api;

use App\Enums\Allocation;
use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\Ticket;
use App\Services\SeatmapBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class MatchController extends Controller
{
    public function __construct(private SeatmapBuilder $seatmaps) {}

    public function index(): JsonResponse
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'stadium'])
            ->orderBy('kickoff_at')
            ->get();

        return response()->json([
            'data' => $matches->map(fn (FootballMatch $match) => $this->seatmaps->formatMatch($match))->all(),
        ]);
    }

    public function seatmap(FootballMatch $match): JsonResponse
    {
        $soldCounts = Ticket::where('match_id', $match->id)
            ->consumingInventory(CarbonImmutable::now())
            ->selectRaw('block_id, count(*) as cnt')
            ->groupBy('block_id')
            ->pluck('cnt', 'block_id');

        $blockPayload = $this->seatmaps->blocks($match)->map(function (array $block) use ($soldCounts) {
            $sold = (int) ($soldCounts->get($block['id'], 0));
            $sellable = Allocation::tryFrom($block['allocation'])?->isSellable() ?? false;

            return $block + ['available' => $sellable ? max(0, $block['capacity'] - $sold) : 0];
        })->values();

        return response()->json($this->seatmaps->header($match) + ['blocks' => $blockPayload]);
    }
}
