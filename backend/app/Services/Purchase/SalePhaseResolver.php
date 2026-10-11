<?php

namespace App\Services\Purchase;

use App\Models\FootballMatch;
use App\Models\SalePhase;
use Carbon\CarbonImmutable;

class SalePhaseResolver
{
    /**
     * starts_at <= now < ends_at のフェーズ。
     */
    public function current(FootballMatch $match, CarbonImmutable $now): ?SalePhase
    {
        return SalePhase::where('match_id', $match->id)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * now より後に始まる直近のフェーズ。
     */
    public function next(FootballMatch $match, CarbonImmutable $now): ?SalePhase
    {
        return SalePhase::where('match_id', $match->id)
            ->where('starts_at', '>', $now)
            ->orderBy('starts_at')
            ->first();
    }
}
