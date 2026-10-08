<?php

namespace App\Support\DemoData;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 基本シード + 用途キーから決定的に導く乱数列。用途キーが違えば独立した流れになる。
 */
final class SeededRandom
{
    private Randomizer $randomizer;

    public function __construct(public readonly int $baseSeed, public readonly string $key)
    {
        $this->randomizer = new Randomizer(new Mt19937(self::deriveSeed($baseSeed, $key)));
    }

    public static function deriveSeed(int $baseSeed, string $key): int
    {
        return (int) hexdec(substr(hash('sha256', $baseSeed.':'.$key), 0, 8));
    }

    /** 同じ基本シードで、このキーの下に独立した流れを作る */
    public function fork(string $subKey): self
    {
        return new self($this->baseSeed, $this->key.'/'.$subKey);
    }

    /** 0 以上 1 未満 */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /**
     * @param  array<int|string, float|int>  $weights  キー => 重み
     */
    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $point = $this->float() * $total;
        $key = null;
        foreach ($weights as $key => $weight) {
            $point -= $weight;
            if ($point < 0) {
                return $key;
            }
        }

        return $key;
    }

    public function poisson(float $lambda): int
    {
        $limit = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= $this->float();
        } while ($p > $limit);

        return $k - 1;
    }

    /** Box-Muller 法 */
    public function normal(float $mean = 0.0, float $sd = 1.0): float
    {
        $u1 = 1.0 - $this->float();
        $u2 = $this->float();

        return $mean + $sd * sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray($items);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }
}
