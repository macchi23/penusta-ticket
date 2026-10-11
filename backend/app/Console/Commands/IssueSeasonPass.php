<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\SeasonPass\SeasonPassIssuer;
use Illuminate\Console\Command;

class IssueSeasonPass extends Command
{
    protected $signature = 'season-pass:issue {season : シーズンの id}';

    protected $description = '年パス固定席を、シーズンの全試合のチケットとして発行する（販売フェーズも展開する）';

    public function handle(SeasonPassIssuer $issuer): int
    {
        $season = Season::findOrFail((int) $this->argument('season'));
        $count = $issuer->issueForSeason($season);
        $this->info("issued {$count} ticket(s)");

        return self::SUCCESS;
    }
}
