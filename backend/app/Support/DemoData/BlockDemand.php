<?php

namespace App\Support\DemoData;

/** ブロックの制約なしの需要。$rate は販売可能席数に対する割合（1 を超えてよい）、$index は需要指数 d */
final readonly class BlockDemand
{
    public function __construct(public float $rate, public float $index) {}
}
