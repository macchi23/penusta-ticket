<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `events` table's model is named ActivityEvent to avoid clashing with
 * Laravel's own event system naming.
 */
class ActivityEvent extends Model
{
    use HasFactory;

    protected $table = 'events';

    protected $fillable = [
        'event_type',
        'user_id',
        'session_id',
        'device',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
