<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Held = 'held';
    case Paid = 'paid';
    case Released = 'released';
    case Expired = 'expired';
}
