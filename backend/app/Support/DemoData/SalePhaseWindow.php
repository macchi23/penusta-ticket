<?php

namespace App\Support\DemoData;

/** sale_phases の 1 行。[startsAt, endsAt) の半開区間（epoch 秒） */
final readonly class SalePhaseWindow
{
    public function __construct(
        public int $id,
        public int $startsAt,
        public int $endsAt,
        public ?string $eligibility,
    ) {}

    public function contains(int $at): bool
    {
        return $at >= $this->startsAt && $at < $this->endsAt;
    }
}
