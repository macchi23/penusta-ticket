<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stadium extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'short_name',
    ];

    public function stands(): HasMany
    {
        return $this->hasMany(Stand::class);
    }

    public function seatCategories(): HasMany
    {
        return $this->hasMany(SeatCategory::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(FootballMatch::class);
    }

    public function allocationTemplates(): HasMany
    {
        return $this->hasMany(AllocationTemplate::class);
    }
}
