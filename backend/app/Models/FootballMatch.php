<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `match` is a reserved word in PHP 8, so the model for the `matches` table
 * is named FootballMatch.
 */
class FootballMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'season_id',
        'home_team_id',
        'away_team_id',
        'stadium_id',
        'kickoff_at',
        'status',
        'section_label',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'kickoff_at' => 'datetime',
            'attributes' => 'array',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function stadium(): BelongsTo
    {
        return $this->belongsTo(Stadium::class);
    }

    public function blockAllocations(): HasMany
    {
        return $this->hasMany(MatchBlockAllocation::class, 'match_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(MatchPrice::class, 'match_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'match_id');
    }

    public function salePhases(): HasMany
    {
        return $this->hasMany(SalePhase::class, 'match_id')->orderBy('starts_at');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'match_id');
    }
}
