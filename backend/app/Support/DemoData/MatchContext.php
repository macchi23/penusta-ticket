<?php

namespace App\Support\DemoData;

final readonly class MatchContext
{
    /**
     * @param  array<string, mixed>  $attributes  matches.attributes
     * @param  array<int, float>  $priceRatios  seat_category_id => 大人価格 / 2025 の基準価格
     * @param  list<SalePhaseWindow>  $phases  sale_phases（開始の昇順）
     */
    public function __construct(
        public int $matchId,
        public int $kickoffAt,
        public array $attributes,
        public SeasonSpan $season,
        public ?MatchStanding $standing = null,
        public array $priceRatios = [],
        public bool $isFirstHome = false,
        public bool $isLastHome = false,
        public array $phases = [],
    ) {}

    /** 注文時刻を含む販売フェーズ。どれにも入らなければ null */
    public function phaseAt(int $at): ?SalePhaseWindow
    {
        foreach ($this->phases as $phase) {
            if ($phase->contains($at)) {
                return $phase;
            }
        }

        return null;
    }

    /** 最初のフェーズの開始（これより前の注文は作らない） */
    public function salesStart(): int
    {
        return $this->phases[0]->startsAt ?? $this->kickoffAt;
    }

    /** 最後のフェーズの終わり（これ以降の注文は作らない。含まない） */
    public function salesEnd(): int
    {
        return $this->phases === [] ? $this->kickoffAt : max(array_map(fn (SalePhaseWindow $p) => $p->endsAt, $this->phases));
    }

    /** 資格を問わないフェーズ（一般販売）。無ければ最後のフェーズ */
    public function openPhase(): ?SalePhaseWindow
    {
        foreach ($this->phases as $phase) {
            if ($phase->eligibility === null) {
                return $phase;
            }
        }

        return $this->phases === [] ? null : $this->phases[array_key_last($this->phases)];
    }

    public function popularity(): int
    {
        return (int) ($this->attributes['opponent_popularity'] ?? 3);
    }
}
