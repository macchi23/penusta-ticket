<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeagueStanding extends Model
{
    protected $fillable = [
        'season_id',
        'round',
        'team_id',
        'position',
        'played',
        'won',
        'drawn',
        'lost',
        'goals_for',
        'goals_against',
        'points',
        'form',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
