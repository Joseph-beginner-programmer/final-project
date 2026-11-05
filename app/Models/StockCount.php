<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\StockCountNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'count_number', 'status', 'posted_by', 'posted_at'])]
class StockCount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'count_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function transitionTo(DocumentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidDocumentStatusTransitionException($this->status, $target, "stock count {$this->count_number}");
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw new StockCountNotEditableException($this);
        }
    }

    public function netAdjustmentValue(): string
    {
        return $this->items->reduce(fn (string $carry, StockCountItem $item) => bcadd($carry, (string) $item->adjustment_value, 2), '0');
    }
}
