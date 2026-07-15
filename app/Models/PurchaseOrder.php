<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PurchaseOrderNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $supplier_id
 * @property Carbon|null $order_date
 */
#[Guarded(['id', 'po_number', 'status', 'approved_by', 'approved_at', 'total_amount'])]
class PurchaseOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function transitionTo(PurchaseOrderStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidStatusTransitionException(
                from: $this->status,
                to: $target,
                purchaseOrderId: $this->id,
            );
        }

        $this->status = $target;
        $this->save();
    }

    public function recalculateTotal(): void
    {
        $this->total_amount = $this->items()->sum('subtotal');
        $this->save();
    }

    public function ensureIsEditable(): void
    {
        if ($this->status !== PurchaseOrderStatus::Draft) {
            throw new PurchaseOrderNotEditableException($this);
        }
    }
}