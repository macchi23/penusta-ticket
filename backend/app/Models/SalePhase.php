<?php

namespace App\Models;

use App\Enums\SalePhaseKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'kind',
        'method',
        'eligibility',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SalePhaseKind::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
