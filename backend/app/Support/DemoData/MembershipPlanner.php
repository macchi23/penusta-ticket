<?php

namespace App\Support\DemoData;

/** 年パス・FC の会員を、前シーズンの成績で決まる更新率と目標人数から決める */
final class MembershipPlanner
{
    /** 目標人数（scale=1）。添字は DemoScenario::seasons() の並び（2021〜2025、2026 ハーフ、2026-27） */
    public const TARGETS = [
        MembershipPlan::SEASON_PASS => [3000, 2400, 2550, 2700, 2900, 4000, 4300],
        MembershipPlan::FANCLUB => [12000, 10500, 11000, 11800, 13000, 16500, 18000],
    ];

    /** 前シーズンの成績区分 => 更新率 */
    public const RENEWAL_RATES = [
        'relegation' => [MembershipPlan::SEASON_PASS => 0.65, MembershipPlan::FANCLUB => 0.70],
        'mid' => [MembershipPlan::SEASON_PASS => 0.80, MembershipPlan::FANCLUB => 0.82],
        'title' => [MembershipPlan::SEASON_PASS => 0.90, MembershipPlan::FANCLUB => 0.90],
    ];

    /** 2025 の 8〜11 月に入会して、その場で FC になる人数（scale=1） */
    private const MID_SEASON_FANCLUB_2025 = 2500;

    /** 年パス・FC の販売開始は、シーズン開幕のこの日数前（valid_from） */
    private const SALE_LEAD_DAYS = 60;

    public function __construct(private readonly int $seed, private readonly float $scale) {}

    /** @param  list<SeasonSpan>  $spans  古い順 */
    public function plan(DemoPopulation $population, array $spans): MembershipPlan
    {
        $plan = new MembershipPlan;

        foreach ($spans as $span) {
            $previousTier = $span->index === 0 ? PerformanceTier::Mid : PerformanceTier::of($spans[$span->index - 1]->definition);
            $rng = new SeededRandom($this->seed, 'membership:'.$span->definition->name);

            $validFrom = gmdate('Y-m-d', (int) strtotime($span->startsOn.' UTC') - self::SALE_LEAD_DAYS * 86400);

            foreach (MembershipPlan::KINDS as $kind) {
                $holders = [];
                $previous = $span->index === 0 ? [] : $plan->holders($kind, $span->index - 1);
                $rate = self::RENEWAL_RATES[$previousTier->value][$kind];
                $renewRng = $rng->fork("renew:{$kind}");
                foreach ($previous as $index => $_) {
                    if ($population->isActiveIn($index, $span) && $renewRng->chance($rate)) {
                        $holders[$index] = $validFrom;
                    }
                }
                $renewed = count($holders);

                $need = max(0, $this->target($kind, $span->index) - $renewed);
                $candidates = [];
                for ($i = 0; $i < $population->count; $i++) {
                    if (! isset($holders[$i]) && $population->joinedAt[$i] <= MembershipPlan::validFromEpoch($validFrom) && $population->isActiveIn($i, $span)) {
                        $candidates[] = $i;
                    }
                }
                foreach ($this->pickByPassion($population, $candidates, $need, $rng->fork("new:{$kind}")) as $index) {
                    $holders[$index] = $validFrom;
                }

                if ($kind === MembershipPlan::FANCLUB && $span->definition->name === '2025') {
                    $holders += $this->midSeasonFanclub($population, $span, $holders, $rng->fork('mid'));
                }

                $plan->set($kind, $span->index, $holders);
                $plan->setStats($kind, $span->index, count($previous), $renewed, count($holders) - $renewed);
            }
        }

        return $plan;
    }

    private function target(string $kind, int $seasonIndex): int
    {
        return (int) round(self::TARGETS[$kind][$seasonIndex] * $this->scale);
    }

    /**
     * 2025 の 8〜11 月に入会したユーザーから FC を選ぶ。valid_from は入会日（JST）
     *
     * @param  array<int, string>  $existing
     * @return array<int, string>
     */
    private function midSeasonFanclub(DemoPopulation $population, SeasonSpan $span, array $existing, SeededRandom $rng): array
    {
        $from = (int) gmmktime(0, 0, 0, 8, 1, 2025);
        $until = (int) gmmktime(0, 0, 0, 12, 1, 2025);
        $candidates = [];
        for ($i = 0; $i < $population->count; $i++) {
            if (! isset($existing[$i]) && $population->joinedAt[$i] >= $from && $population->joinedAt[$i] < $until
                && $population->isActiveIn($i, $span)) {
                $candidates[] = $i;
            }
        }

        $picked = [];
        $count = (int) round(self::MID_SEASON_FANCLUB_2025 * $this->scale);
        foreach ($this->pickByPassion($population, $candidates, $count, $rng) as $index) {
            $picked[$index] = gmdate('Y-m-d', $population->joinedAt[$index] + 9 * 3600);
        }

        return $picked;
    }

    /**
     * 熱心さを重みにした非復元抽出（Efraimidis-Spirakis）
     *
     * @param  list<int>  $candidates
     * @return list<int>
     */
    private function pickByPassion(DemoPopulation $population, array $candidates, int $count, SeededRandom $rng): array
    {
        if ($count <= 0 || $candidates === []) {
            return [];
        }
        if ($count >= count($candidates)) {
            return $candidates;
        }

        $keys = [];
        foreach ($candidates as $index) {
            $keys[$index] = log(1.0 - $rng->float()) / $population->passion[$index];
        }
        arsort($keys);
        $picked = array_slice(array_keys($keys), 0, $count);
        sort($picked);

        return $picked;
    }
}
