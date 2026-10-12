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
        return $this->beforeDeadline($match, $now) && $this->hasStarted($match, $now);
    }

    /** 試合が on_sale で、締切前か。 */
    private function beforeDeadline(FootballMatch $match, CarbonImmutable $now): bool
    {
        return $match->status === 'on_sale' && $now < $this->closesAt($match);
    }

    /** 一般販売フェーズが始まっているか。 */
    private function hasStarted(FootballMatch $match, CarbonImmutable $now): bool
    {
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

    /** 一般販売フェーズの開始（リセールの開始）。フェーズが無ければ null。 */
    public function opensAt(FootballMatch $match): ?CarbonImmutable
    {
        $start = SalePhase::where('match_id', $match->id)
            ->where('kind', SalePhaseKind::General->value)
            ->min('starts_at');

        return $start === null ? null : CarbonImmutable::parse($start, 'UTC');
    }

    /**
     * 一覧の表示状態。open は canView と同値。
     *
     * @return 'open'|'login_required'|'not_started'|'closed'
     */
    public function boardStatus(?User $user, FootballMatch $match, CarbonImmutable $now): string
    {
        if ($user === null) {
            return 'login_required';
        }
        if (! $this->beforeDeadline($match, $now)) {
            return 'closed';
        }

        return $this->hasStarted($match, $now) ? 'open' : 'not_started';
    }

    /** @throws ResaleException */
    public function assertOpen(FootballMatch $match, CarbonImmutable $now): void
    {
        if (! $this->isOpen($match, $now)) {
            throw ResaleException::closed();
        }
    }
}
