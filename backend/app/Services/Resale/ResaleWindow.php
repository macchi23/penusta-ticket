<?php

namespace App\Services\Resale;

use App\Enums\SalePhaseKind;
use App\Exceptions\ResaleException;
use App\Models\FootballMatch;
use App\Models\SalePhase;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * リセールの受付期間。一般販売フェーズの開始からキックオフ - close_minutes まで（試合が on_sale のときだけ）。
 */
class ResaleWindow
{
    public function closesAt(FootballMatch $match): CarbonImmutable
    {
        return CarbonImmutable::instance($match->kickoff_at)
            ->subMinutes((int) config('ticketing.resale.close_minutes_before_kickoff'));
    }

    public function isOpen(FootballMatch $match, CarbonImmutable $now): bool
    {
        if ($match->status !== 'on_sale' || $now >= $this->closesAt($match)) {
            return false;
        }

        // general フェーズは eligibility が null(誰でも買える)前提なので、資格は見ない
        return SalePhase::where('match_id', $match->id)
            ->where('kind', SalePhaseKind::General->value)
            ->where('starts_at', '<=', $now)
            ->exists();
    }

    /** リセールの一覧を見せてよいか。未ログイン、on_sale でない、期間外は不可。 */
    public function canView(?User $user, FootballMatch $match, CarbonImmutable $now): bool
    {
        return $user !== null && $this->isOpen($match, $now);
    }

    /** @throws ResaleException */
    public function assertOpen(FootballMatch $match, CarbonImmutable $now): void
    {
        if (! $this->isOpen($match, $now)) {
            throw ResaleException::closed();
        }
    }
}
