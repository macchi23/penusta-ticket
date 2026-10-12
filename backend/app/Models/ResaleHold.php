<?php

namespace App\Models;

use App\Enums\ResaleHoldStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResaleHold extends Model
{
    /** サーバーで決めた値だけを渡すこと（リクエスト配列を fill しない） */
    protected $fillable = [
        'listing_id',
        'user_id',
        'match_id',
        'status',
        'expires_at',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ResaleHoldStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** 有効な仮押さえ = held かつ期限前。この条件はここ 1 か所で定義する。 */
    public function scopeLive(Builder $query, CarbonInterface $now): Builder
    {
        return $query->where('status', ResaleHoldStatus::Held->value)->where('expires_at', '>', $now);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(ResaleListing::class, 'listing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
