<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Guarded(['id'])]
class InventoryLot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_initial' => 'decimal:2',
            'quantity_remaining' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'value_remaining' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
