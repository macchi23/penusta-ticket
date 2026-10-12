<?php

namespace App\Enums;

enum ResaleHoldStatus: string
{
    case Held = 'held';
    case Released = 'released';
    case Expired = 'expired';
    case Purchased = 'purchased';
}
