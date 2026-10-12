<?php

namespace App\Console\Commands;

use App\Services\Resale\ResaleService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExpireResaleListings extends Command
{
    protected $signature = 'resale:expire-listings';

    protected $description = '締切を過ぎたリセール出品を失効させる';

    public function handle(ResaleService $resale): int
    {
        $count = $resale->expireDue(CarbonImmutable::now());
        $this->info("expired {$count} listing(s)");

        return self::SUCCESS;
    }
}
