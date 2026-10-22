<?php

namespace App\Models;

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

    public function receivedValue(): string
    {
        $journal = JournalEntry::query()
            ->where('source_type', 'purchase_order_receipt')
            ->where('source_id', $this->id)
            ->whereNull('reversal_of_id')
            ->with('lines')
            ->first();

        if ($journal) {
            return $journal->totalDebit();
        }

        $lot = $this->inventoryLot;

        return $lot ? bcmul((string) $lot->quantity_initial, (string) $lot->unit_cost, 2) : '0';
    }
}