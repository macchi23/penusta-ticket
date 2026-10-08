<?php

namespace App\Support\DemoData;

final readonly class DemoTeam
{
    /**
     * @param  float  $attack  得点力の倍率（大きいほど点が取れる）
     * @param  float  $defense  失点の倍率（小さいほど守れる）
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $shortName,
        public string $color,
        public int $popularity,
        public float $attack,
        public float $defense,
        public bool $isHomeClub = false,
    ) {}
}
