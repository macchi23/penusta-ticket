<?php

namespace App\Support\DemoData;

/** 累積重みの二分探索で重み付き抽選する */
final class WeightedSampler
{
    /** @var list<float> */
    private array $cumulative = [];

    private float $total = 0.0;

    /**
     * @param  list<int>  $keys
     * @param  list<float|int>  $weights  keys と同じ並び。0 以上
     */
    public function __construct(private readonly array $keys, array $weights)
    {
        foreach ($weights as $weight) {
            $this->total += $weight;
            $this->cumulative[] = $this->total;
        }
    }

    public function isEmpty(): bool
    {
        return $this->total <= 0.0;
    }

    public function pick(SeededRandom $rng): int
    {
        $point = $rng->float() * $this->total;
        $low = 0;
        $high = count($this->cumulative) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->cumulative[$mid] > $point) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $this->keys[$low];
    }
}
