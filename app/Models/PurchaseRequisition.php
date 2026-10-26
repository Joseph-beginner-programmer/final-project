<?php

namespace App\Models;

use App\Enums\PurchaseRequisitionStatus;
use App\Exceptions\InvalidPurchaseRequisitionException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property PurchaseRequisitionStatus $status
 */
#[Guarded(['id', 'requisition_number', 'status', 'rejection_reason', 'purchase_order_id', 'processed_by', 'processed_at'])]
class PurchaseRequisition extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseRequisitionStatus::class,
            'needed_by' => 'date',
            'processed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function ensureIsSubmitted(): void
    {
        if ($this->status !== PurchaseRequisitionStatus::Submitted) {
            throw new InvalidPurchaseRequisitionException(InvalidPurchaseRequisitionException::NOT_OPEN, $this->requisition_number);
        }
    }
}
