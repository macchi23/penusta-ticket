<?php

namespace App\Services\SeasonPass;

use App\Models\FootballMatch;
use App\Models\SalePhase;
use App\Services\Purchase\SalePhaseTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 試合の販売フェーズを展開し、年パス固定席を発行する。何度呼んでも同じ結果になる。
 */
class SalePhaseProvisioner
{
    public function __construct(private SeasonPassIssuer $issuer) {}

    /** @return int 発行した年パスチケット数 */
    public function provision(FootballMatch $match): int
    {
        return DB::transaction(function () use ($match) {
            if (! SalePhase::where('match_id', $match->id)->exists()) {
                foreach (SalePhaseTemplate::forKickoff(CarbonImmutable::instance($match->kickoff_at)) as $phase) {
                    SalePhase::create($phase + ['match_id' => $match->id]);
                }
            }

            return $this->issuer->issueForMatch($match);
        }, 3);
    }
}
