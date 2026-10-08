<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\Fixture;
use App\Support\DemoData\FixtureScheduler;
use App\Support\DemoData\JapaneseHolidays;
use App\Support\DemoData\SeasonFormat;
use App\Support\DemoData\SeasonResult;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class FixtureSchedulerTest extends TestCase
{
    private function jst(Fixture $f): DateTimeImmutable
    {
        return $f->kickoffUtc->setTimezone(new DateTimeZone('Asia/Tokyo'));
    }

    /** @return list<Fixture> */
    private function all(SeasonResult $season): array
    {
        return array_merge(...array_values($season->rounds));
    }

    public function test_round_counts_and_home_away_pairs(): void
    {
        $expected = ['spring_autumn' => [38, 19], 'half' => [18, 9], 'autumn_spring' => [38, 19]];
        foreach (DemoLeagueTest::league()->seasons as $season) {
            [$rounds, $penustaHome] = $expected[$season->definition->format->value];
            $this->assertCount($rounds, $season->rounds, $season->definition->name);

            $n = count($season->teamIds);
            $pairs = [];
            foreach ($this->all($season) as $f) {
                $pairs[] = "{$f->homeId}-{$f->awayId}";
            }
            $this->assertCount($n * ($n - 1), array_unique($pairs), $season->definition->name);
            $this->assertSame($n * ($n - 1), count($pairs));

            $this->assertSame($penustaHome, count(array_filter($this->all($season), fn ($f) => $f->homeId === 1)), $season->definition->name);
            foreach ($season->rounds as $fixtures) {
                $this->assertCount($n / 2, $fixtures);
            }
        }
    }

    public function test_penusta_alternates_home_and_away(): void
    {
        foreach (DemoLeagueTest::league()->seasons as $season) {
            $previous = null;
            foreach ($season->rounds as $round => $fixtures) {
                $mine = array_values(array_filter($fixtures, fn ($f) => $f->homeId === 1 || $f->awayId === 1));
                $this->assertCount(1, $mine);
                $isHome = $mine[0]->homeId === 1;
                if ($previous !== null) {
                    $this->assertNotSame($previous, $isHome, "{$season->definition->name} round {$round}");
                }
                $previous = $isHome;
            }
        }
    }

    public function test_every_team_alternates_home_and_away_reasonably(): void
    {
        foreach (DemoLeagueTest::league()->seasons as $season) {
            $half = count($season->rounds) / 2;
            $seq = array_fill_keys($season->teamIds, []);
            foreach ($season->rounds as $fixtures) {
                foreach ($fixtures as $f) {
                    $seq[$f->homeId][] = 1;
                    $seq[$f->awayId][] = 0;
                }
            }
            foreach ($seq as $team => $flags) {
                $homes = array_sum(array_slice($flags, 0, $half));
                $this->assertGreaterThanOrEqual(intdiv($half, 2) - 1, $homes, "{$season->definition->name} team {$team}");
                $this->assertLessThanOrEqual(intdiv($half, 2) + 2, $homes, "{$season->definition->name} team {$team}");
                $longest = $run = 0;
                $prev = null;
                foreach ($flags as $flag) {
                    $run = $flag === $prev ? $run + 1 : 1;
                    $prev = $flag;
                    $longest = max($longest, $run);
                }
                $this->assertLessThanOrEqual(4, $longest, "{$season->definition->name} team {$team}");
            }
        }
    }

    public function test_no_team_plays_on_consecutive_days(): void
    {
        foreach (DemoLeagueTest::league()->seasons as $season) {
            $days = array_fill_keys($season->teamIds, []);
            foreach ($this->all($season) as $f) {
                $day = $this->jst($f)->setTime(0, 0);
                $days[$f->homeId][] = $day;
                $days[$f->awayId][] = $day;
            }
            foreach ($days as $team => $list) {
                usort($list, fn ($a, $b) => $a <=> $b);
                for ($i = 1; $i < count($list); $i++) {
                    $this->assertGreaterThanOrEqual(2, $list[$i - 1]->diff($list[$i])->days, "{$season->definition->name} team {$team} {$list[$i]->format('Y-m-d')}");
                }
            }
        }
    }

    public function test_second_half_mirrors_first_half(): void
    {
        foreach (DemoLeagueTest::league()->seasons as $season) {
            $half = count($season->rounds) / 2;
            for ($r = 1; $r <= $half; $r++) {
                $first = array_map(fn ($f) => [$f->homeId, $f->awayId], $season->rounds[$r]);
                $second = array_map(fn ($f) => [$f->awayId, $f->homeId], $season->rounds[$r + $half]);
                sort($first);
                sort($second);
                $this->assertSame($first, $second);
            }
        }
    }

    public function test_one_match_per_team_per_day_and_rounds_are_chronological(): void
    {
        foreach (DemoLeagueTest::league()->seasons as $season) {
            $seen = [];
            $lastDay = '';
            foreach ($season->rounds as $round => $fixtures) {
                $roundDays = [];
                foreach ($fixtures as $f) {
                    $day = $this->jst($f)->format('Y-m-d');
                    foreach ([$f->homeId, $f->awayId] as $team) {
                        $this->assertArrayNotHasKey("$team@$day", $seen, "{$season->definition->name} $team $day");
                        $seen["$team@$day"] = true;
                    }
                    $roundDays[] = $day;
                }
                $this->assertGreaterThan($lastDay, min($roundDays), "{$season->definition->name} round {$round}");
                $lastDay = max($roundDays);
            }
        }
    }

    public function test_spring_autumn_dates_and_international_breaks(): void
    {
        foreach (DemoLeagueTest::league()->seasons as $season) {
            $d = $season->definition;
            if ($d->format !== SeasonFormat::SpringAutumn) {
                continue;
            }
            $fixtures = $this->all($season);
            $first = $this->jst($season->rounds[1][0]);
            $this->assertSame('6', $first->format('N'));
            $this->assertGreaterThanOrEqual("{$d->startYear}-02-20", $first->format('Y-m-d'));
            $this->assertLessThan("{$d->startYear}-02-27", $first->format('Y-m-d'));

            $last = $this->jst($season->rounds[38][0]);
            $this->assertGreaterThanOrEqual("{$d->startYear}-12-01", $last->format('Y-m-d'));
            $this->assertLessThanOrEqual("{$d->startYear}-12-07", $last->format('Y-m-d'));

            $breakDays = [];
            foreach (FixtureScheduler::internationalBreakSaturdays($d->startYear, $d->format) as $sat) {
                $breakDays[$sat->format('Y-m-d')] = true;
                $breakDays[$sat->modify('+1 day')->format('Y-m-d')] = true;
            }
            $this->assertCount(10, $breakDays);
            foreach ($fixtures as $f) {
                $this->assertArrayNotHasKey($this->jst($f)->format('Y-m-d'), $breakDays, "{$d->name} break week");
            }
        }
    }

    public function test_break_weekends_fall_in_expected_months(): void
    {
        $saturdays = FixtureScheduler::internationalBreakSaturdays(2024, SeasonFormat::SpringAutumn);
        $this->assertSame(['03-23', '06-01', '09-07', '10-12', '11-16'], array_map(fn ($d) => $d->format('m-d'), $saturdays));
        foreach ($saturdays as $sat) {
            $this->assertSame('6', $sat->format('N'));
        }
    }

    public function test_half_season_dates(): void
    {
        $season = DemoLeagueTest::league()->season('2026');
        $first = $this->jst($season->rounds[1][0])->format('Y-m-d');
        $last = $this->jst($season->rounds[18][0])->format('Y-m-d');
        $this->assertGreaterThanOrEqual('2026-02-14', $first);
        $this->assertLessThanOrEqual('2026-02-20', $first);
        $this->assertGreaterThanOrEqual('2026-05-30', $last);
        $this->assertLessThanOrEqual('2026-06-07', $last);

        $breaks = FixtureScheduler::internationalBreakSaturdays(2026, SeasonFormat::Half);
        $this->assertCount(1, $breaks);
        foreach ($this->all($season) as $f) {
            $day = $this->jst($f)->format('Y-m-d');
            $this->assertNotSame($breaks[0]->format('Y-m-d'), $day);
            $this->assertNotSame($breaks[0]->modify('+1 day')->format('Y-m-d'), $day);
        }
    }

    public function test_autumn_spring_dates_and_winter_break(): void
    {
        $season = DemoLeagueTest::league()->season('2026-27');
        $days = array_map(fn ($f) => $this->jst($f)->format('Y-m-d'), $this->all($season));

        foreach ($season->rounds[1] as $f) {
            $this->assertContains($this->jst($f)->format('Y-m-d'), ['2026-08-08', '2026-08-09']);
        }
        foreach ($season->rounds[38] as $f) {
            $this->assertContains($this->jst($f)->format('Y-m-d'), ['2027-06-05', '2027-06-06']);
        }
        foreach ($days as $day) {
            $this->assertFalse($day > '2026-12-13' && $day < '2027-02-20', "winter break: $day");
        }
        $this->assertContains('2026-12-12', $days);
        $this->assertContains('2027-02-20', $days);
        $this->assertSame(3, count(array_filter($season->rounds, fn ($fixtures) => (int) $this->jst($fixtures[0])->format('N') < 6)));
    }

    public function test_weekday_rounds_are_wednesdays_or_holidays_and_avoid_winter_months(): void
    {
        $holidays = new JapaneseHolidays;
        foreach (DemoLeagueTest::league()->seasons as $season) {
            foreach ($season->rounds as $fixtures) {
                $day = $this->jst($fixtures[0]);
                if ((int) $day->format('N') >= 6) {
                    continue;
                }
                $this->assertTrue($day->format('N') === '3' || $holidays->isHoliday($day), $day->format('Y-m-d'));
                $this->assertNotContains((int) $day->format('n'), [12, 1, 2]);
                foreach ($fixtures as $f) {
                    $this->assertSame($day->format('Y-m-d'), $this->jst($f)->format('Y-m-d'));
                }
            }
        }
    }

    public function test_golden_week_weekdays_are_preferred_for_weekday_rounds(): void
    {
        // 週末で足りない年は、4/29・5/3〜5/6 の平日が他の祝日・水曜より先に選ばれる
        $inGoldenWeek = 0;
        foreach (DemoLeagueTest::league()->seasons as $season) {
            if ($season->definition->format === SeasonFormat::AutumnSpring) {
                continue;
            }
            $weekdayDays = [];
            foreach ($season->rounds as $fixtures) {
                $day = $this->jst($fixtures[0]);
                if ((int) $day->format('N') < 6) {
                    $weekdayDays[] = $day->format('m-d');
                }
            }
            $golden = array_filter($weekdayDays, fn ($md) => $md === '04-29' || ($md >= '05-03' && $md <= '05-06'));
            $inGoldenWeek += count($golden);
            $this->assertNotEmpty($golden, "{$season->definition->name}: ".implode(',', $weekdayDays));
        }
        $this->assertGreaterThanOrEqual(6, $inGoldenWeek);
    }

    public function test_kickoff_hours_and_penusta_home_weekdays(): void
    {
        $holidays = new JapaneseHolidays;
        $sat = $sun = 0;
        foreach (DemoLeagueTest::league()->seasons as $season) {
            foreach ($this->all($season) as $f) {
                $jst = $this->jst($f);
                $month = (int) $jst->format('n');
                $hour = (int) $jst->format('G');
                $dow = $jst->format('N');
                if ($dow === '3' && ! $holidays->isHoliday($jst)) {
                    $this->assertSame(19, $hour);
                } elseif ($month >= 6 && $month <= 9) {
                    $this->assertSame($dow === '6' ? 19 : 18, $hour);
                } elseif ($month === 12 || $month === 2) {
                    $this->assertContains($hour, [13, 14]);
                } else {
                    $this->assertContains($hour, [14, 15]);
                }
                if ($f->homeId === 1) {
                    $sat += $dow === '6' ? 1 : 0;
                    $sun += $dow === '7' ? 1 : 0;
                }
            }
        }
        $this->assertGreaterThan($sun * 1.5, $sat);
        $this->assertGreaterThan(0, $sun);
    }
}
