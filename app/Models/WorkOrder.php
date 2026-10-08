<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property WorkOrderStatus $status
 */
#[Guarded(['id', 'wo_number', 'status', 'quantity_good', 'quantity_reject', 'overhead_rate', 'planned_overhead_cost', 'printed_at', 'closed_by', 'closed_at'])]
class WorkOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'quantity_target' => 'decimal:2',
            'quantity_good' => 'decimal:2',
            'quantity_reject' => 'decimal:2',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'planned_machine_hours' => 'decimal:2',
            'overhead_rate' => 'decimal:2',
            'planned_overhead_cost' => 'decimal:2',
            'printed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function productionFormula(): BelongsTo
    {
        return $this->belongsTo(ProductionFormula::class);
    }
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Copied from the formula at creation — the plan this WO was approved with.
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * Copied from the formula at creation, already scaled to quantity_target.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    public function labors(): HasMany
    {
        return $this->hasMany(WorkOrderLabor::class);
    }

    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    /**
     * Materials can be issued once the WO is on the floor, and until it's finished.
     */
    public function canReceiveMaterials(): bool
    {
        return in_array($this->status, [WorkOrderStatus::Released, WorkOrderStatus::InProgress], true);
    }

    /**
     * Quantity already issued (posted issues only) per product id — the "already issued" column.
     *
     * @return array<int, string>
     */
    public function issuedQuantities(): array
    {
        return MaterialIssueItem::query()
            ->whereHas('materialIssue', fn ($query) => $query->where('work_order_id', $this->id)->where('status', 'posted'))
            ->selectRaw('product_id, sum(quantity) as issued')
            ->groupBy('product_id')
            ->pluck('issued', 'product_id')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    public function transitionTo(WorkOrderStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidWorkOrderStatusTransitionException(
                from: $this->status,
                to: $target,
                workOrderId: $this->id,
            );
        }

        $this->status = $target;
        $this->save();
    }
}
