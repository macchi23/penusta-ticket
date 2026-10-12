<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'match_id',
        'status',
        'kind',
        'sale_phase_id',
        'total',
        'channel',
        'expires_at',
        'ordered_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'status' => OrderStatus::class,
            'kind' => OrderKind::class,
            'expires_at' => 'immutable_datetime',
            'ordered_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function salePhase(): BelongsTo
    {
        return $this->belongsTo(SalePhase::class);
    }

    /** kind = resale の注文が買った出品 */
    public function resaleListing(): HasOne
    {
        return $this->hasOne(ResaleListing::class, 'buyer_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
