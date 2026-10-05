<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Services\SeatmapBuilder;
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
            'data' => $matches->map(fn (FootballMatch $match) => $this->seatmaps->formatMatch($match) + [
                'editable' => $match->kickoff_at->isFuture(),
            ])->all(),
        ]);
    }
}
