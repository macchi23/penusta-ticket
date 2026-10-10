<?php

namespace App\Support\DemoData;

/** あるシーズンに買い手になりうるユーザーと、ブロック種別ごとの重み付き抽選 */
final class SeasonCrowd
{
    public const FANCLUB_WEIGHT = 1.8;

    private const MAX_PICK_TRIES = 20;

    /** @var list<int> */
    private array $eligible = [];

    /** @var array<int, int> ユーザー添字 => 年パスの valid_from（epoch） */
    private array $passFrom = [];

    /** @var array<int, int> ユーザー添字 => FC の valid_from（epoch） */
    private array $fanclubFrom = [];

    /** @var array<string, WeightedSampler> */
    private array $samplers = [];

    public function __construct(
        private readonly DemoPopulation $population,
        private readonly MembershipPlan $plan,
        private readonly SeasonSpan $span,
        private readonly int $userIdOffset,
    ) {
        for ($i = 0; $i < $population->count; $i++) {
            if ($population->isActiveIn($i, $span)) {
                $this->eligible[] = $i;
            }
        }
        foreach ($plan->holders(MembershipPlan::SEASON_PASS, $span->index) as $index => $from) {
            $this->passFrom[$index] = MembershipPlan::validFromEpoch($from);
        }
        foreach ($plan->holders(MembershipPlan::FANCLUB, $span->index) as $index => $from) {
            $this->fanclubFrom[$index] = MembershipPlan::validFromEpoch($from);
        }
    }

    public function isEmpty(): bool
    {
        return $this->eligible === [];
    }

    /** users.id */
    public function userId(int $userIndex): int
    {
        return $this->userIdOffset + $userIndex + 1;
    }

    public function joinedAt(int $userIndex): int
    {
        return $this->population->joinedAt[$userIndex];
    }

    /** その時点で有効な年パス会員か（有効期間はシーズン終了まで。注文はキックオフ前なので終端は見ない） */
    public function hasSeasonPassAt(int $userIndex, int $at): bool
    {
        return isset($this->passFrom[$userIndex]) && $this->passFrom[$userIndex] <= $at;
    }

    /** FC の重みを付けて選ばれたが、その時点ではまだ会員でない */
    public function isOverweightedAt(int $userIndex, int $at): bool
    {
        return isset($this->fanclubFrom[$userIndex]) && $this->fanclubFrom[$userIndex] > $at;
    }

    /**
     * 入会が $joinedBy 以前のユーザーを重みで選ぶ（入会前の注文を作らないため、外れたら引き直す）
     *
     * @return int ユーザー添字
     */
    public function pickBuyer(string $categoryType, SeededRandom $rng, int $joinedBy): int
    {
        $sampler = $this->sampler($categoryType);
        $index = $sampler->pick($rng);
        for ($try = 1; $try < self::MAX_PICK_TRIES && $this->population->joinedAt[$index] > $joinedBy; $try++) {
            $index = $sampler->pick($rng);
        }

        return $index;
    }

    /** 買わずに離脱するセッションのユーザー。入会前なら匿名（null） */
    public function pickBrowser(SeededRandom $rng, int $at): ?int
    {
        $index = $this->eligible[$rng->int(0, count($this->eligible) - 1)];

        return $this->population->joinedAt[$index] <= $at ? $index : null;
    }

    private function sampler(string $categoryType): WeightedSampler
    {
        if (isset($this->samplers[$categoryType])) {
            return $this->samplers[$categoryType];
        }

        $weights = [];
        foreach ($this->eligible as $index) {
            $age = $this->population->age[$index];
            $male = $this->population->male[$index];
            $demographic = match ($categoryType) {
                'goal_back' => ($age >= 20 && $age <= 40 ? 3 : 1) * ($male ? 2 : 1),
                'main' => $age >= 40 ? 3 : 1,
                default => 1,
            };
            $fanclub = isset($this->fanclubFrom[$index]) ? self::FANCLUB_WEIGHT : 1.0;
            $weights[] = $this->population->passion[$index] * $demographic * $fanclub;
        }

        return $this->samplers[$categoryType] = new WeightedSampler($this->eligible, $weights);
    }
}
