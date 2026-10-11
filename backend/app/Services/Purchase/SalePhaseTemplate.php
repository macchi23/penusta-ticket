<?php

namespace App\Services\Purchase;

use App\Enums\MembershipKind;
use App\Enums\SalePhaseKind;
use Carbon\CarbonImmutable;

/**
 * キックオフ時刻から標準の販売フェーズ（FC 先行 → 一般）を組み立てる。
 * 2025_06_01_000010 のバックフィルと同じ日数。
 */
final class SalePhaseTemplate
{
    public const PRESALE_DAYS_BEFORE = 42;

    public const GENERAL_DAYS_BEFORE = 35;

    /** @return list<array{kind:string, method:string, eligibility:?string, starts_at:CarbonImmutable, ends_at:CarbonImmutable}> */
    public static function forKickoff(CarbonImmutable $kickoff): array
    {
        $general = $kickoff->subDays(self::GENERAL_DAYS_BEFORE);

        return [
            [
                'kind' => SalePhaseKind::FanclubPresale->value,
                'method' => 'first_come',
                'eligibility' => MembershipKind::Fanclub->value,
                'starts_at' => $kickoff->subDays(self::PRESALE_DAYS_BEFORE),
                'ends_at' => $general,
            ],
            [
                'kind' => SalePhaseKind::General->value,
                'method' => 'first_come',
                'eligibility' => null,
                'starts_at' => $general,
                'ends_at' => $kickoff,
            ],
        ];
    }
}
