<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\MaterialIssueNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'issue_number', 'status', 'issued_by', 'issued_at'])]
class MaterialIssue extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialIssueItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function transitionTo(DocumentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidDocumentStatusTransitionException($this->status, $target, "material issue {$this->issue_number}");
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw new MaterialIssueNotEditableException($this);
        }
    }

    public function totalCost(): string
    {
        return $this->items->reduce(fn (string $carry, MaterialIssueItem $item) => bcadd($carry, (string) $item->total_cost, 2), '0');
    }
}
