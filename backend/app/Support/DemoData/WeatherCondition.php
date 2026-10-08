<?php

namespace App\Support\DemoData;

enum WeatherCondition: string
{
    case Sunny = '晴';
    case Cloudy = '曇';
    case Rainy = '雨';
    case Snowy = '雪';
}
