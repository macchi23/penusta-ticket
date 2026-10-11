<?php

namespace App\Services\Purchase;

use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\User;

/**
 * 販売方式（sale_phases.method）固有の販売条件。方式に依存しない検査は SaleConditions。
 */
interface SaleMethod
{
    public function assertCanHold(User $user, FootballMatch $match, Block $block, int $heldCount): void;
}
