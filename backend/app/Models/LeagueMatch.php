<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeagueMatch extends Model
{
    protected $fillable = [
        'season_id',
        'round',
        'kickoff_at',
        'home_team_id',
        'away_team_id',
        'home_score',
        'away_score',
        'match_id',
    ];

    protected function casts(): array
    {
        return [
            'kickoff_at' => 'datetime',
            'round' => 'integer',
            'home_score' => 'integer',
            'away_score' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
