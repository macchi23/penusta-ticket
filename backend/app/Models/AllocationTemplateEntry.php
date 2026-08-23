<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllocationTemplateEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'allocation_template_id',
        'block_id',
        'allocation',
    ];

    public function allocationTemplate(): BelongsTo
    {
        return $this->belongsTo(AllocationTemplate::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }
}
