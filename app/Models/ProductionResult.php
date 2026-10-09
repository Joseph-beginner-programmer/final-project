<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\ProductionResultNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'result_number', 'status', 'material_cost', 'labor_cost', 'overhead_cost', 'total_cost', 'unit_cost', 'posted_by', 'posted_at'])]
class ProductionResult extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'production_date' => 'date',
            'quantity_good' => 'decimal:2',
            'quantity_reject' => 'decimal:2',
            'machine_hours' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'overhead_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ProductionResultMaterial::class);
    }

    public function labors(): HasMany
    {
        return $this->hasMany(ProductionResultLabor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Material used per product id, summed over the issue lines it was allocated to.
     *
     * @return array<int, string>
     */
    public function usedQuantities(): array
    {
        return $this->materials->groupBy('product_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->quantity, 2), '0'))
            ->all();
    }

    public function transitionTo(DocumentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidDocumentStatusTransitionException($this->status, $target, "production result {$this->result_number}");
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw new ProductionResultNotEditableException($this);
        }
    }
}
