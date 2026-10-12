<?php

namespace App\Enums;

enum ResaleListingStatus: string
{
    case Active = 'active';
    case Sold = 'sold';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
