<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\SeededRandom;
use PHPUnit\Framework\TestCase;

class SeededRandomTest extends TestCase
{
    private function sequence(SeededRandom $rng, int $n = 10): array
    {
        return array_map(fn () => $rng->int(0, 1_000_000), range(1, $n));
    }

    public function test_same_seed_and_key_give_same_sequence(): void
    {
        $this->assertSame(
            $this->sequence(new SeededRandom(42, 'a')),
            $this->sequence(new SeededRandom(42, 'a')),
        );
    }

    public function test_different_key_or_seed_gives_different_sequence(): void
    {
        $base = $this->sequence(new SeededRandom(42, 'a'));
        $this->assertNotSame($base, $this->sequence(new SeededRandom(42, 'b')));
        $this->assertNotSame($base, $this->sequence(new SeededRandom(43, 'a')));
    }

    public function test_streams_are_independent_of_each_other(): void
    {
        $alone = new SeededRandom(42, 'b');
        $expected = $this->sequence($alone);

        $a = new SeededRandom(42, 'a');
        $b = new SeededRandom(42, 'b');
        $this->sequence($a, 500);

        $this->assertSame($expected, $this->sequence($b));
    }

    public function test_fork_is_deterministic_and_separate(): void
    {
        $parent = new SeededRandom(1, 'x');
        $this->assertSame($this->sequence($parent->fork('y')), $this->sequence((new SeededRandom(1, 'x'))->fork('y')));
        $this->assertNotSame($this->sequence($parent->fork('y')), $this->sequence($parent->fork('z')));
    }

    public function test_float_int_and_chance_ranges(): void
    {
        $rng = new SeededRandom(7, 'range');
        for ($i = 0; $i < 500; $i++) {
            $f = $rng->float();
            $this->assertGreaterThanOrEqual(0.0, $f);
            $this->assertLessThan(1.0, $f);
            $n = $rng->int(3, 5);
            $this->assertGreaterThanOrEqual(3, $n);
            $this->assertLessThanOrEqual(5, $n);
        }
    }

    public function test_weighted_follows_weights(): void
    {
        $rng = new SeededRandom(7, 'weighted');
        $counts = ['a' => 0, 'b' => 0, 'c' => 0];
        for ($i = 0; $i < 4000; $i++) {
            $counts[$rng->weighted(['a' => 1, 'b' => 3, 'c' => 0])]++;
        }
        $this->assertSame(0, $counts['c']);
        $this->assertEqualsWithDelta(0.75, $counts['b'] / 4000, 0.04);
    }

    public function test_poisson_and_normal_have_expected_moments(): void
    {
        $rng = new SeededRandom(7, 'moments');
        $n = 5000;
        $poisson = array_sum(array_map(fn () => $rng->poisson(1.5), range(1, $n))) / $n;
        $normal = array_map(fn () => $rng->normal(10.0, 2.0), range(1, $n));
        $mean = array_sum($normal) / $n;
        $sd = sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $normal)) / $n);

        $this->assertEqualsWithDelta(1.5, $poisson, 0.08);
        $this->assertEqualsWithDelta(10.0, $mean, 0.15);
        $this->assertEqualsWithDelta(2.0, $sd, 0.1);
    }

    public function test_shuffle_keeps_elements(): void
    {
        $shuffled = (new SeededRandom(7, 's'))->shuffle(range(1, 20));
        $sorted = $shuffled;
        sort($sorted);
        $this->assertSame(range(1, 20), $sorted);
    }
}
