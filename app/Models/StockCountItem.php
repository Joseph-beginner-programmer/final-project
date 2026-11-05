<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Guarded(['id', 'system_quantity', 'variance_quantity', 'unit_cost', 'adjustment_value'])]
class StockCountItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:2',
            'counted_quantity' => 'decimal:2',
            'variance_quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'adjustment_value' => 'decimal:2',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
