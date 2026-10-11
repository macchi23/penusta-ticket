<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'block_id',
        'seat_id',
        'season_pass_seat_id',
        'status',
        'qr_uuid',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * 在庫を消費しているチケット（購入済み、または期限内の仮押さえ）。
     */
    public function scopeConsumingInventory(Builder $q, CarbonImmutable $now): void
    {
        $q->where(function (Builder $w) use ($now) {
            $w->where('status', TicketStatus::Paid->value)
                ->orWhere(function (Builder $h) use ($now) {
                    $h->where('status', TicketStatus::Held->value)
                        ->where('expires_at', '>', $now);
                });
        });
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function seasonPassSeat(): BelongsTo
    {
        return $this->belongsTo(SeasonPassSeat::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
