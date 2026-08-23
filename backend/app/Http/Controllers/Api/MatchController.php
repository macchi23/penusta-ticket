<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class MatchController extends Controller
{
    /**
     * 有効（在庫を消費している）とみなすチケットステータス。
     */
    private const ACTIVE_TICKET_STATUSES = ['reserved', 'paid', 'issued', 'checked_in'];

    public function index(): JsonResponse
    {
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'stadium'])
            ->orderBy('kickoff_at')
            ->get();

        return response()->json([
            'data' => $matches->map(fn (FootballMatch $match) => $this->formatMatch($match))->all(),
        ]);
    }

    public function seatmap(FootballMatch $match): JsonResponse
    {
        $match->load(['homeTeam', 'awayTeam', 'stadium']);

        $blocks = Block::with(['stand', 'seatCategory'])->get();

        $allocations = $match->blockAllocations()
            ->pluck('allocation', 'block_id');

        $soldCounts = Ticket::where('match_id', $match->id)
            ->whereIn('status', self::ACTIVE_TICKET_STATUSES)
            ->selectRaw('block_id, count(*) as cnt')
            ->groupBy('block_id')
            ->pluck('cnt', 'block_id');

        $blockPayload = $blocks->map(function (Block $block) use ($allocations, $soldCounts) {
            $allocation = $allocations->get($block->id, 'closed');
            $sold = (int) ($soldCounts->get($block->id, 0));
            $available = $allocation === 'closed' ? 0 : max(0, $block->capacity - $sold);

            return [
                'id' => $block->id,
                'code' => $block->code,
                'stand' => $block->stand->name,
                'seating_type' => $block->seating_type,
                'seat_category' => $block->seatCategory->name,
                'allocation' => $allocation,
                'capacity' => $block->capacity,
                'available' => $available,
                'geometry' => $block->geometry,
            ];
        })->values();

        return response()->json([
            'match' => $this->formatMatch($match),
            'viewbox' => ['width' => 1000, 'height' => 700],
            'blocks' => $blockPayload,
        ]);
    }

    private function formatMatch(FootballMatch $match): array
    {
        return [
            'id' => $match->id,
            'kickoff_at' => Carbon::parse($match->kickoff_at)->setTimezone('Asia/Tokyo')->toIso8601String(),
            'status' => $match->status,
            'section_label' => $match->section_label,
            'stadium' => $match->stadium->name,
            'home_team' => $this->formatTeam($match->homeTeam),
            'away_team' => $this->formatTeam($match->awayTeam),
        ];
    }

    private function formatTeam($team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'short_name' => $team->short_name,
            'color' => $team->color,
        ];
    }
}
