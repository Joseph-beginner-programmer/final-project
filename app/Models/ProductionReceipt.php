<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\ProductionReceiptNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'receipt_number', 'status', 'unit_cost', 'total_value', 'shortage_value', 'received_by', 'received_at'])]
class ProductionReceipt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'quantity_expected' => 'decimal:2',
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_value' => 'decimal:2',
            'shortage_value' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function productionResult(): BelongsTo
    {
        return $this->belongsTo(ProductionResult::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lot(): MorphOne
    {
        return $this->morphOne(InventoryLot::class, 'source');
    }

    public function shortageQuantity(): string
    {
        return bcsub((string) $this->quantity_expected, (string) $this->quantity, 2);
    }

    public function transitionTo(DocumentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidDocumentStatusTransitionException($this->status, $target, "production receipt {$this->receipt_number}");
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw new ProductionReceiptNotEditableException($this);
        }
    }
}
