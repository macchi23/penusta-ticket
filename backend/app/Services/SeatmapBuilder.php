<?php

namespace App\Services;

use App\Models\Block;
use App\Models\FootballMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 座席マップ（一般向け・管理者向け共通）の基本部分を組み立てる。
 */
class SeatmapBuilder
{
    public function formatMatch(FootballMatch $match): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'stadium']);

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

    /**
     * @return array{match: array, viewbox: array{width: int, height: int}}
     */
    public function header(FootballMatch $match): array
    {
        return [
            'match' => $this->formatMatch($match),
            'viewbox' => ['width' => 1000, 'height' => 700],
        ];
    }

    /**
     * ブロックの基本項目。割当行が無いブロックは closed 扱い。
     *
     * @return Collection<int, array{id: int, code: string, stand: string, seating_type: string, seat_category: string, allocation: string, capacity: int, geometry: mixed}>
     */
    public function blocks(FootballMatch $match): Collection
    {
        $allocations = $match->blockAllocations()->pluck('allocation', 'block_id');

        return Block::with(['stand', 'seatCategory'])->get()->map(fn (Block $block) => [
            'id' => $block->id,
            'code' => $block->code,
            'stand' => $block->stand->name,
            'seating_type' => $block->seating_type,
            'seat_category' => $block->seatCategory->name,
            'allocation' => $allocations->get($block->id, 'closed'),
            'capacity' => $block->capacity,
            'geometry' => $block->geometry,
        ])->values();
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
