<?php

namespace App\Console\Commands;

use App\Services\Purchase\DbHoldExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExpireHolds extends Command
{
    protected $signature = 'tickets:expire-holds';

    protected $description = '期限切れの仮押さえカートを回収する';

    public function handle(DbHoldExpiry $expiry): int
    {
        $count = $expiry->expireOverdue(CarbonImmutable::now());
        $this->info("expired {$count} cart(s)");

        return self::SUCCESS;
    }
}
