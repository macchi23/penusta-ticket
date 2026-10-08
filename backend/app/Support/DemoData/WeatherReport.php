<?php

namespace App\Support\DemoData;

final readonly class WeatherReport
{
    public function __construct(public WeatherCondition $condition, public float $temperature) {}
}
