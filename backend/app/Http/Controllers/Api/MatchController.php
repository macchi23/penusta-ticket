<?php

namespace App\Http\Controllers\Api;

use App\Enums\Allocation;
use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\Ticket;
use App\Services\Purchase\SaleGate;
use App\Services\SeatmapBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class MatchController extends Controller
{
    public function __construct(private SeatmapBuilder $seatmaps, private SaleGate $gate) {}

    public function index(): JsonResponse
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'stadium', 'salePhases'])
            ->orderBy('kickoff_at')
            ->get();

        return response()->json([
            'data' => $matches->map(fn (FootballMatch $match) => $this->seatmaps->formatMatch($match))->all(),
        ]);
    }

    public function seatmap(FootballMatch $match): JsonResponse
    {
        $now = CarbonImmutable::now();
        // 購入できない人（未ログイン・フェーズ外・資格なし）には残数を伏せる。ユーザーは X-Demo-User-Id で決まる
        $visible = $this->gate->canViewSeats(auth('demo')->user(), $match, $now);

        $soldCounts = $visible
            ? Ticket::where('match_id', $match->id)
                ->consumingInventory($now)
                ->selectRaw('block_id, count(*) as cnt')
                ->groupBy('block_id')
                ->pluck('cnt', 'block_id')
            : collect();

        $blockPayload = $this->seatmaps->blocks($match)->map(function (array $block) use ($soldCounts, $visible) {
            $sold = (int) ($soldCounts->get($block['id'], 0));
            $sellable = Allocation::tryFrom($block['allocation'])?->isSellable() ?? false;

            return $block + ['available' => $visible ? ($sellable ? max(0, $block['capacity'] - $sold) : 0) : null];
        })->values();

        return response()->json($this->seatmaps->header($match) + ['blocks' => $blockPayload]);
    }
}
