<?php

namespace App\Models;

use App\Enums\PurchaseOrderItemReceiptCondition;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Guarded(['id'])]
class PurchaseOrderReceipt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:2',
            'receipt_condition' => PurchaseOrderItemReceiptCondition::class
        ];
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function purchaseOrderReceiptBatch(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceiptBatch::class);
    }

    public function inventoryLot(): MorphOne
    {
        return $this->morphOne(InventoryLot::class, 'source');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}