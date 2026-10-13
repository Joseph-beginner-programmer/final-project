<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Guarded(['id', 'total_cost', 'variance_value'])]
class MaterialReturnItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity_expected' => 'decimal:2',
            'quantity' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'variance_value' => 'decimal:2',
        ];
    }

    public function materialReturn(): BelongsTo
    {
        return $this->belongsTo(MaterialReturn::class);
    }

    public function materialIssueItem(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueItem::class);
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
