<?php

namespace App\Enums;

enum Allocation: string
{
    case Home = 'home';
    case Away = 'away';
    case Buffer = 'buffer';
    case Closed = 'closed';

    public function isSellable(): bool
    {
        return $this === self::Home || $this === self::Away;
    }
}
