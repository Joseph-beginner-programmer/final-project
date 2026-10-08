<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class ProductionFormula extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'output_quantity' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionFormulaItem::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function scaleMaterialQuantity(string $materialQuantity, string $targetQuantity): string
    {
        if (bccomp((string) $this->output_quantity, '0', 2) <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul($materialQuantity, $targetQuantity, 4), (string) $this->output_quantity, 2);
    }
}
