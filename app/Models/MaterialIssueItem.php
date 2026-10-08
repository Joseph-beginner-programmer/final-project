<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Guarded(['id', 'total_cost', 'quantity_consumed'])]
class MaterialIssueItem extends Model
{
    use HasFactory;

    // table has no created_at/updated_at (matches the ERD)
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'quantity_consumed' => 'decimal:2',
        ];
    }

    public function materialIssue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The FIFO OUT movements this line produced on posting — one per lot drawn from.
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /**
     * Average FIFO cost per unit of this line (a line can span lots at different costs).
     */
    public function unitCost(): string
    {
        return bccomp((string) $this->quantity, '0', 2) > 0
            ? bcdiv((string) $this->total_cost, (string) $this->quantity, 2)
            : '0.00';
    }
}
