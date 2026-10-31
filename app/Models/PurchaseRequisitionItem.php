<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class PurchaseRequisitionItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function isFulfilled(): bool
    {
        return $this->purchase_order_id !== null
            && ! in_array($this->purchaseOrder->status, [PurchaseOrderStatus::Rejected, PurchaseOrderStatus::Cancelled], true);
    }
}
