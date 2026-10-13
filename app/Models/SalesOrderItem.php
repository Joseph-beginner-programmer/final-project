<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id', 'quantity_delivered', 'quantity_returned', 'subtotal'])]
class SalesOrderItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:2',
            'quantity_delivered' => 'decimal:2',
            'quantity_returned' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remainingQuantity(): string
    {
        $remaining = bcsub((string) $this->quantity_ordered, (string) $this->quantity_delivered, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0';
    }

    public function isFullyDelivered(): bool
    {
        return bccomp((string) $this->quantity_delivered, (string) $this->quantity_ordered, 2) >= 0;
    }
}
