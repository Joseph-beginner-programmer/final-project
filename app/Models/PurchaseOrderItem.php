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
            'quantity_returned' => 'decimal:2',
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
        return bcsub((string) $this->quantity_ordered, $this->netReceived(), 2);
    }

    public function netReceived(): string
    {
        return bcsub((string) $this->quantity_received, (string) $this->quantity_returned, 2);
    }

    public function isFullyReceived(): bool
    {
        return bccomp($this->netReceived(), (string) $this->quantity_ordered, 2) >= 0;
    }

    public function chargeShare(): string
    {
        $po = $this->purchaseOrder;
        $capitalizedCharges = (string) $po->charges()->where('include_in_cost', true)->sum('amount');
        $itemsSubtotal = (string) $po->items()->sum('subtotal');

        if (bccomp($capitalizedCharges, '0', 2) <= 0 || bccomp($itemsSubtotal, '0', 2) <= 0) {
            return '0';
        }

        return bcdiv(bcmul($capitalizedCharges, (string) $this->subtotal, 6), $itemsSubtotal, 6);
    }

    public function landedUnitCost(): string
    {
        $unitPrice = (string) $this->unit_price;

        if (bccomp((string) $this->quantity_ordered, '0', 2) <= 0) {
            return $unitPrice;
        }

        return bcadd($unitPrice, bcdiv($this->chargeShare(), (string) $this->quantity_ordered, 6), 6);
    }
}