<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    use HasFactory;

    protected $fillable = [
        'stand_id',
        'seat_category_id',
        'code',
        'seating_type',
        'capacity',
        'geometry',
    ];

    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'capacity' => 'integer',
        ];
    }

    public function stand(): BelongsTo
    {
        return $this->belongsTo(Stand::class);
    }

    public function seatCategory(): BelongsTo
    {
        return $this->belongsTo(SeatCategory::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function allocationTemplateEntries(): HasMany
    {
        return $this->hasMany(AllocationTemplateEntry::class);
    }

    public function matchBlockAllocations(): HasMany
    {
        return $this->hasMany(MatchBlockAllocation::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
