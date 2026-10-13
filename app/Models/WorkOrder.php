<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property WorkOrderStatus $status
 */
#[Guarded(['id', 'wo_number', 'status', 'quantity_good', 'quantity_reject', 'overhead_rate', 'planned_overhead_cost', 'printed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason'])]
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
            'cancelled_at' => 'datetime',
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

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
     * @return Collection<int, MaterialIssueItem>
     */
    public function postedIssueItems(bool $lock = false): Collection
    {
        return MaterialIssueItem::query()
            ->select('material_issue_items.*')
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')
            ->where('material_issues.work_order_id', $this->id)
            ->where('material_issues.status', 'posted')
            ->orderBy('material_issues.issued_at')
            ->orderBy('material_issues.id')
            ->with(['product', 'stockMovements'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    public function productionResult(): HasOne
    {
        return $this->hasOne(ProductionResult::class);
    }

    public function canRecordResult(): bool
    {
        return $this->status === WorkOrderStatus::InProgress;
    }

    public function canReceiveMaterials(): bool
    {
        return in_array($this->status, [WorkOrderStatus::Released, WorkOrderStatus::InProgress], true);
    }

    /**
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
