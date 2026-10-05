<?php

namespace App\Models;

use App\Enums\Allocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 割当変更の監査行（append-only）。
 */
class MatchBlockAllocationChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'match_id',
        'block_id',
        'from_allocation',
        'to_allocation',
        'changed_by',
        'change_set_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_allocation' => Allocation::class,
            'to_allocation' => Allocation::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
