<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AllocationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'stadium_id',
        'name',
    ];

    public function stadium(): BelongsTo
    {
        return $this->belongsTo(Stadium::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AllocationTemplateEntry::class);
    }
}
