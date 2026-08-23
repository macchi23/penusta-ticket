<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'stadium_id',
        'name',
        'display_order',
    ];

    public function stadium(): BelongsTo
    {
        return $this->belongsTo(Stadium::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    public function matchPrices(): HasMany
    {
        return $this->hasMany(MatchPrice::class);
    }
}
