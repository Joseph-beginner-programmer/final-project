<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Enums\Direction;
use App\Enums\StockMovementType;

#[Guarded(['id'])]
class StockMovement extends Model {
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'type' => StockMovementType::class,
            'created_at' => 'datetime',
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_value' => 'decimal:2',
        ];
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}