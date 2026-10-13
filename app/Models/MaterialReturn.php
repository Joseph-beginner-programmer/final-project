<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\MaterialReturnNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'return_number', 'status', 'received_by', 'received_at'])]
class MaterialReturn extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'received_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function productionResult(): BelongsTo
    {
        return $this->belongsTo(ProductionResult::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialReturnItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function transitionTo(DocumentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidDocumentStatusTransitionException($this->status, $target, "material return {$this->return_number}");
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw new MaterialReturnNotEditableException($this);
        }
    }

    public function varianceValue(): string
    {
        return $this->items->reduce(fn (string $carry, MaterialReturnItem $item) => bcadd($carry, (string) $item->variance_value, 2), '0');
    }
}
