<?php

namespace App\Support\DemoData;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** 総当たり 2 回戦の組み合わせ（circle method）と、形式ごとの日程 */
final class FixtureScheduler
{
    /** 国際マッチウィークの探索開始日（月 => 日）。その日以降の最初の土曜が対象の週末 */
    private const BREAK_WINDOWS = [3 => 22, 6 => 1, 9 => 1, 10 => 11, 11 => 11];

    private const HOME_SATURDAY_RATE = 0.7;

    private readonly JapaneseHolidays $holidays;

    public function __construct(?JapaneseHolidays $holidays = null)
    {
        $this->holidays = $holidays ?? new JapaneseHolidays;
    }

    /**
     * 国際マッチウィークにあたる土曜（JST 0 時）。日曜はその翌日
     *
     * @return list<DateTimeImmutable>
     */
    public static function internationalBreakSaturdays(int $year, SeasonFormat $format): array
    {
        $months = match ($format) {
            SeasonFormat::SpringAutumn => [3, 6, 9, 10, 11],
            SeasonFormat::Half => [3],
            SeasonFormat::AutumnSpring => [],
        };

        return array_map(
            fn (int $month) => self::saturdayOnOrAfter(self::date($year, $month, self::BREAK_WINDOWS[$month])),
            $months,
        );
    }

    /**
     * @param  list<int>  $teamIds
     * @return array<int, list<Fixture>> 節番号（1 始まり）=> 試合
     */
    public function schedule(SeasonDefinition $season, array $teamIds, int $homeClubId, SeededRandom $rng): array
    {
        $pairings = $this->pairings($teamIds, $homeClubId, $rng);
        $slots = $this->slots($season, count($pairings), $rng);

        $rounds = [];
        foreach ($pairings as $i => $pairs) {
            $rounds[$i + 1] = $this->placeRound($i + 1, $slots[$i], $pairs, $homeClubId, $rng);
        }

        return $rounds;
    }

    /**
     * 前半 n-1 節 + ホーム/アウェー反転の後半 n-1 節。ホームクラブは固定位置に置き、前半は交互、後半は前半の反転
     *
     * @param  list<int>  $teamIds
     * @return list<list<array{0: int, 1: int}>> 節ごとの [home, away]
     */
    private function pairings(array $teamIds, int $homeClubId, SeededRandom $rng): array
    {
        $others = $rng->shuffle(array_values(array_filter($teamIds, fn ($id) => $id !== $homeClubId)));
        $n = count($others) + 1;
        $fixed = $homeClubId;

        // 向きは貪欲に決める: 連続ホーム/アウェーが短くなる側を選び、同点なら (i + r) の偶奇。ホームクラブだけは節ごとに交互に固定
        $first = [];
        $streak = array_fill_keys($teamIds, 0);
        $rotating = $others;
        for ($r = 0; $r < $n - 1; $r++) {
            $line = [$fixed, ...$rotating];
            $pairs = [];
            for ($i = 0; $i < intdiv($n, 2); $i++) {
                $a = $line[$i];
                $b = $line[$n - 1 - $i];
                if ($i === 0) {
                    $aHome = $r % 2 === 0;
                } else {
                    $costAHome = max(self::streakAfter($streak[$a], true), self::streakAfter($streak[$b], false));
                    $costBHome = max(self::streakAfter($streak[$a], false), self::streakAfter($streak[$b], true));
                    $aHome = $costAHome === $costBHome ? ($i + $r) % 2 === 0 : $costAHome < $costBHome;
                }
                $streak[$a] = self::streakValue($streak[$a], $aHome);
                $streak[$b] = self::streakValue($streak[$b], ! $aHome);
                $pairs[] = $aHome ? [$a, $b] : [$b, $a];
            }
            $first[] = $pairs;
            array_unshift($rotating, array_pop($rotating));
        }

        $second = array_map(
            fn (array $pairs) => array_map(fn (array $p) => [$p[1], $p[0]], $pairs),
            $first,
        );

        return [...$first, ...$second];
    }

    /** 連続ホームを正、連続アウェーを負で持つ */
    private static function streakValue(int $streak, bool $home): int
    {
        if ($home) {
            return $streak > 0 ? $streak + 1 : 1;
        }

        return $streak < 0 ? $streak - 1 : -1;
    }

    private static function streakAfter(int $streak, bool $home): int
    {
        return abs(self::streakValue($streak, $home));
    }

    /**
     * @return list<array{kind: string, days: list<DateTimeImmutable>}> 時系列順。kind は weekend / midweek / holiday
     */
    private function slots(SeasonDefinition $season, int $roundCount, SeededRandom $rng): array
    {
        $y = $season->startYear;
        $format = $season->format;

        $pauseAfter = $pauseBefore = null;
        switch ($format) {
            case SeasonFormat::SpringAutumn:
                $first = self::saturdayOnOrAfter(self::date($y, 2, 20));
                $last = self::saturdayOnOrAfter(self::date($y, 12, 1));
                break;
            case SeasonFormat::Half:
                $first = self::saturdayOnOrAfter(self::date($y, 2, 14));
                $last = self::saturdayOnOrAfter(self::date($y, 6, 1));
                break;
            case SeasonFormat::AutumnSpring:
                $first = self::saturdayOnOrAfter(self::date($y, 8, 8));
                $last = self::saturdayOnOrAfter(self::date($y + 1, 6, 5));
                $pauseAfter = self::nthSaturday($y, 12, 2);
                $pauseBefore = self::nthSaturday($y + 1, 2, 3);
                break;
        }

        $breaks = [];
        foreach (self::internationalBreakSaturdays($y, $format) as $saturday) {
            $breaks[$saturday->format('Y-m-d')] = true;
        }

        $weekends = [];
        for ($sat = $first; $sat <= $last; $sat = $sat->modify('+7 days')) {
            if (isset($breaks[$sat->format('Y-m-d')])) {
                continue;
            }
            if ($pauseAfter !== null && $sat > $pauseAfter && $sat < $pauseBefore) {
                continue;
            }
            $weekends[] = $sat;
        }

        // 週末が多すぎる年は、内側の週末をランダムに空ける
        $surplus = count($weekends) - $roundCount;
        if ($surplus > 0) {
            $interior = range(1, count($weekends) - 2);
            foreach (array_slice($rng->shuffle($interior), 0, $surplus) as $index) {
                unset($weekends[$index]);
            }
            $weekends = array_values($weekends);
        }

        $slots = array_map(fn (DateTimeImmutable $sat) => ['kind' => 'weekend', 'days' => [$sat, $sat->modify('+1 day')]], $weekends);

        $missing = $roundCount - count($weekends);
        if ($missing > 0) {
            foreach ($this->midweekDays($first, $last, $breaks, $missing, $rng) as $item) {
                $slots[] = $item;
            }
            usort($slots, fn ($a, $b) => $a['days'][0] <=> $b['days'][0]);
            $slots = $this->restrictWeekendsNextToWeekdays($slots);
        }

        return $slots;
    }

    /**
     * 連日で試合をしないよう、金曜の節の翌日（土）・月曜の節の前日（日）の枠を使わない週末の節は、残る 1 日に寄せる
     *
     * @param  list<array{kind: string, days: list<DateTimeImmutable>}>  $slots
     * @return list<array{kind: string, days: list<DateTimeImmutable>, only?: int}>
     */
    private function restrictWeekendsNextToWeekdays(array $slots): array
    {
        foreach ($slots as $i => $slot) {
            if ($slot['kind'] === 'weekend') {
                continue;
            }
            $day = $slot['days'][0];
            $dow = $day->format('N');
            if ($dow === '5' && isset($slots[$i + 1]) && $slots[$i + 1]['kind'] === 'weekend' && $slots[$i + 1]['days'][0] == $day->modify('+1 day')) {
                $slots[$i + 1]['only'] = 1;
            }
            if ($dow === '1' && isset($slots[$i - 1]) && $slots[$i - 1]['kind'] === 'weekend' && $slots[$i - 1]['days'][1] == $day->modify('-1 day')) {
                $slots[$i - 1]['only'] = 0;
            }
        }

        return $slots;
    }

    /**
     * 週末で足りない分の平日開催日。4/29・5/3〜5/6 の平日、その他の祝日の平日、水曜の順に優先。同じ週に 2 つ置かない
     *
     * @param  array<string, true>  $breaks
     * @return list<array{kind: string, days: list<DateTimeImmutable>}>
     */
    private function midweekDays(DateTimeImmutable $first, DateTimeImmutable $last, array $breaks, int $count, SeededRandom $rng): array
    {
        $goldenWeekCandidates = [];
        $holidayCandidates = [];
        $wednesdayCandidates = [];
        for ($d = $first->modify('+2 days'); $d < $last; $d = $d->modify('+1 day')) {
            $dow = (int) $d->format('N');
            if ($dow > 5 || in_array((int) $d->format('n'), [12, 1, 2], true)) {
                continue;
            }
            $weekend = $d->modify('+'.(6 - $dow).' days')->format('Y-m-d');
            if (isset($breaks[$weekend])) {
                continue;
            }
            if ($this->holidays->isHoliday($d)) {
                if ($d->format('m-d') === '04-29' || ($d->format('m') === '05' && (int) $d->format('j') >= 3 && (int) $d->format('j') <= 6)) {
                    $goldenWeekCandidates[] = $d;
                } else {
                    $holidayCandidates[] = $d;
                }
            } elseif ($dow === 3) {
                $wednesdayCandidates[] = $d;
            }
        }

        $chosen = [];
        $usedWeeks = [];
        foreach ([[$goldenWeekCandidates, 'holiday'], [$holidayCandidates, 'holiday'], [$wednesdayCandidates, 'midweek']] as [$candidates, $kind]) {
            foreach ($rng->shuffle($candidates) as $d) {
                if (count($chosen) >= $count) {
                    break 2;
                }
                $week = $d->format('o-W');
                if (isset($usedWeeks[$week])) {
                    continue;
                }
                // 金曜の節と、その次の月曜の節の間に挟まれた週末は、土日どちらにも置けなくなる
                foreach ($chosen as $c) {
                    if (abs($c['days'][0]->diff($d)->days) === 3 && in_array($c['days'][0]->format('N').$d->format('N'), ['51', '15'], true)) {
                        continue 2;
                    }
                }
                $usedWeeks[$week] = true;
                $chosen[] = ['kind' => $kind, 'days' => [$d]];
            }
        }

        if (count($chosen) < $count) {
            throw new RuntimeException('平日開催の候補日が足りない');
        }

        return $chosen;
    }

    /**
     * @param  array{kind: string, days: list<DateTimeImmutable>}  $slot
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return list<Fixture>
     */
    private function placeRound(int $round, array $slot, array $pairs, int $homeClubId, SeededRandom $rng): array
    {
        $fixtures = [];
        foreach ($pairs as [$home, $away]) {
            if (isset($slot['only'])) {
                $day = $slot['days'][$slot['only']];
            } elseif ($slot['kind'] === 'weekend') {
                $saturday = $home === $homeClubId ? $rng->chance(self::HOME_SATURDAY_RATE) : $rng->chance(0.5);
                $day = $saturday ? $slot['days'][0] : $slot['days'][1];
            } else {
                $day = $slot['days'][0];
            }
            $fixtures[] = new Fixture($round, $this->kickoff($day, $slot['kind'], $rng), $home, $away);
        }
        usort($fixtures, fn (Fixture $a, Fixture $b) => [$a->kickoffUtc, $a->homeId] <=> [$b->kickoffUtc, $b->homeId]);

        return $fixtures;
    }

    private function kickoff(DateTimeImmutable $day, string $kind, SeededRandom $rng): DateTimeImmutable
    {
        $month = (int) $day->format('n');
        $isSaturday = $day->format('N') === '6';

        $time = match (true) {
            $kind === 'midweek' => '19:00',
            $month >= 6 && $month <= 9 => $isSaturday ? '19:00' : '18:00',
            $month === 12 || $month === 2 => $rng->pick(['13:00', '14:00']),
            default => $rng->pick(['14:00', '15:00']),
        };

        return new DateTimeImmutable($day->format('Y-m-d').' '.$time, new DateTimeZone(DemoScenario::TIMEZONE))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private static function date(int $year, int $month, int $day): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%d-%02d-%02d 00:00:00', $year, $month, $day), new DateTimeZone(DemoScenario::TIMEZONE));
    }

    private static function saturdayOnOrAfter(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->modify('+'.((6 - (int) $date->format('N') + 7) % 7).' days');
    }

    private static function nthSaturday(int $year, int $month, int $n): DateTimeImmutable
    {
        return self::saturdayOnOrAfter(self::date($year, $month, 1))->modify('+'.(7 * ($n - 1)).' days');
    }
}
