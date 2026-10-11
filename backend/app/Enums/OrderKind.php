<?php

namespace App\Enums;

enum OrderKind: string
{
    case Purchase = 'purchase';
    case SeasonPass = 'season_pass';
}
