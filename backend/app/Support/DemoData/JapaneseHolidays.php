<?php

namespace App\Support\DemoData;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** 日本の祝日（2020 年以降。2021 の五輪による移動は扱わない） */
final class JapaneseHolidays
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    public function isHoliday(DateTimeInterface $date): bool
    {
        return isset($this->forYear((int) $date->format('Y'))[$date->format('Y-m-d')]);
    }

    public function name(DateTimeInterface $date): ?string
    {
        return $this->forYear((int) $date->format('Y'))[$date->format('Y-m-d')] ?? null;
    }

    /** @return array<string, string> 'Y-m-d' => 祝日名（日付順） */
    public function forYear(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $fixed = [
            sprintf('%d-01-01', $year) => '元日',
            sprintf('%d-02-11', $year) => '建国記念の日',
            sprintf('%d-02-23', $year) => '天皇誕生日',
            sprintf('%d-03-%02d', $year, self::vernalEquinoxDay($year)) => '春分の日',
            sprintf('%d-04-29', $year) => '昭和の日',
            sprintf('%d-05-03', $year) => '憲法記念日',
            sprintf('%d-05-04', $year) => 'みどりの日',
            sprintf('%d-05-05', $year) => 'こどもの日',
            sprintf('%d-09-%02d', $year, self::autumnalEquinoxDay($year)) => '秋分の日',
            sprintf('%d-11-03', $year) => '文化の日',
            sprintf('%d-11-23', $year) => '勤労感謝の日',
            self::nthMonday($year, 1, 2) => '成人の日',
            self::nthMonday($year, 9, 3) => '敬老の日',
        ];
        // 2021 は五輪のため海の日・スポーツの日・山の日が移動した
        $moved = $year === 2021
            ? ['2021-07-22' => '海の日', '2021-07-23' => 'スポーツの日', '2021-08-08' => '山の日']
            : [
                sprintf('%d-08-11', $year) => '山の日',
                self::nthMonday($year, 7, 3) => '海の日',
                self::nthMonday($year, 10, 2) => 'スポーツの日',
            ];
        $fixed += $moved;
        ksort($fixed);

        $holidays = $fixed;

        // 国民の休日: 前日と翌日が祝日の平日
        foreach (array_keys($fixed) as $day) {
            $d = new DateTimeImmutable($day, new DateTimeZone('UTC'));
            $between = $d->modify('+1 day');
            $key = $between->format('Y-m-d');
            if (! isset($holidays[$key]) && isset($fixed[$d->modify('+2 days')->format('Y-m-d')]) && $between->format('N') !== '7') {
                $holidays[$key] = '国民の休日';
            }
        }

        // 振替休日: 日曜の祝日は、次の祝日でない日
        foreach ($fixed as $day => $_) {
            $d = new DateTimeImmutable($day, new DateTimeZone('UTC'));
            if ($d->format('N') !== '7') {
                continue;
            }
            $next = $d->modify('+1 day');
            while (isset($holidays[$next->format('Y-m-d')])) {
                $next = $next->modify('+1 day');
            }
            $holidays[$next->format('Y-m-d')] = '休日（振替休日）';
        }

        ksort($holidays);

        return $this->cache[$year] = $holidays;
    }

    private static function nthMonday(int $year, int $month, int $n): string
    {
        $first = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month), new DateTimeZone('UTC'));
        $offset = (8 - (int) $first->format('N')) % 7;

        return $first->modify('+'.($offset + 7 * ($n - 1)).' days')->format('Y-m-d');
    }

    private static function vernalEquinoxDay(int $year): int
    {
        return (int) floor(20.8431 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    }

    private static function autumnalEquinoxDay(int $year): int
    {
        return (int) floor(23.2488 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    }
}
