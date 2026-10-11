<?php

namespace App\Services\Purchase;

use App\Exceptions\PurchaseException;
use App\Models\FootballMatch;
use App\Models\SalePhase;
use App\Models\User;
use Carbon\CarbonImmutable;

class SaleGate
{
    public function __construct(
        private SalePhaseResolver $resolver,
        private MembershipEligibility $eligibility,
    ) {}

    /**
     * 今買えるフェーズを返す。販売していなければ not_on_sale、資格がなければ not_eligible。
     */
    public function open(User $user, FootballMatch $match, CarbonImmutable $now): SalePhase
    {
        $phase = $this->resolver->current($match, $now)
            ?? throw PurchaseException::notOnSale('この試合は現在販売していません。');

        if (! $this->eligibility->isEligible($user, $phase->eligibility, $now)) {
            throw PurchaseException::notEligible();
        }

        return $phase;
    }

    /**
     * open() と同じ判定を、例外を投げずに返す。フェーズ外なら [null, false]。
     *
     * @return array{0: ?SalePhase, 1: bool}
     */
    public function inquire(User $user, FootballMatch $match, CarbonImmutable $now): array
    {
        $phase = $this->resolver->current($match, $now);

        return [$phase, $phase !== null && $this->eligibility->isEligible($user, $phase->eligibility, $now)];
    }
}
