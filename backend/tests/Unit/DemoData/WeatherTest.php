<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\Weather;
use App\Support\DemoData\WeatherCondition;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class WeatherTest extends TestCase
{
    private function kickoff(string $jst): DateTimeImmutable
    {
        return (new DateTimeImmutable($jst, new DateTimeZone('Asia/Tokyo')))->setTimezone(new DateTimeZone('UTC'));
    }

    public function test_is_deterministic(): void
    {
        $k = $this->kickoff('2025-05-10 14:00');
        $this->assertEquals((new Weather(1))->forKickoff($k), (new Weather(1))->forKickoff($k));
    }

    public function test_temperature_stays_within_three_degrees_of_normal_and_night_is_cooler(): void
    {
        $weather = new Weather(5);
        for ($d = 1; $d <= 28; $d++) {
            $date = sprintf('2025-08-%02d', $d);
            $day = $weather->forKickoff($this->kickoff("$date 14:00"))->temperature;
            $night = $weather->forKickoff($this->kickoff("$date 19:00"))->temperature;
            $this->assertEqualsWithDelta(28.8, $day, 3.05);
            $this->assertEqualsWithDelta(2.0, $day - $night, 0.01);
        }
    }

    public function test_rain_rate_by_month_and_snow_only_in_winter(): void
    {
        $weather = new Weather(9);
        $rain = ['jun' => 0, 'oct' => 0];
        $n = 0;
        foreach (range(2000, 2099) as $year) {
            foreach (range(1, 28) as $d) {
                $n++;
                $rain['jun'] += $weather->forKickoff($this->kickoff(sprintf('%d-06-%02d 14:00', $year, $d)))->condition === WeatherCondition::Rainy ? 1 : 0;
                $oct = $weather->forKickoff($this->kickoff(sprintf('%d-10-%02d 14:00', $year, $d)))->condition;
                $rain['oct'] += $oct === WeatherCondition::Rainy ? 1 : 0;
                $this->assertNotSame(WeatherCondition::Snowy, $oct);
            }
        }
        $this->assertEqualsWithDelta(0.40, $rain['jun'] / $n, 0.04);
        $this->assertEqualsWithDelta(0.20, $rain['oct'] / $n, 0.04);

        $snow = 0;
        foreach (range(2000, 2099) as $year) {
            foreach (range(1, 28) as $d) {
                $snow += $weather->forKickoff($this->kickoff(sprintf('%d-02-%02d 14:00', $year, $d)))->condition === WeatherCondition::Snowy ? 1 : 0;
            }
        }
        $this->assertGreaterThan(0, $snow);
    }
}
