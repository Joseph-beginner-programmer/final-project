<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id', 'quantity_received', 'subtotal'])]
class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:2',
            'quantity_received' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }
    
    public function syncSubtotal(): void
    {
        $this->subtotal = bcmul((string) $this->quantity_ordered, (string) $this->unit_price, 2);
    }

    public function syncQuantityReceived(): void
    {
        $this->quantity_received = $this->receipts()->sum('quantity_received');
    }
    public function remainingQuantity(): string
    {
        return bcsub((string) $this->quantity_ordered, (string) $this->quantity_received, 2);
    }

    public function isFullyReceived(): bool
    {
        return bccomp((string) $this->quantity_received, (string) $this->quantity_ordered, 2) >= 0;
    }
}