<?php

namespace App\Models;

use App\Enums\ResaleListingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResaleListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'seller_user_id',
        'match_id',
        'block_id',
        'ticket_type_id',
        'face_price',
        'price',
        'seller_fee',
        'buyer_fee',
        'payout',
        'status',
        'closes_at',
        'buyer_order_id',
        'new_ticket_id',
    ];

    protected function casts(): array
    {
        return [
            'face_price' => 'integer',
            'price' => 'integer',
            'seller_fee' => 'integer',
            'buyer_fee' => 'integer',
            'payout' => 'integer',
            'status' => ResaleListingStatus::class,
            'closes_at' => 'immutable_datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function newTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'new_ticket_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function buyerOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'buyer_order_id');
    }
}
