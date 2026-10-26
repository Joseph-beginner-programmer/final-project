<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\SalesDeliveryNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'delivery_number', 'status', 'delivered_by', 'delivered_at'])]
class SalesDelivery extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'delivered_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDeliveryItem::class);
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }

    public function liveInvoice(): ?SalesInvoice
    {
        return $this->salesInvoices()->where('status', '!=', \App\Enums\SalesInvoiceStatus::Cancelled->value)->first();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function transitionTo(DocumentStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidDocumentStatusTransitionException($this->status, $target, "sales delivery {$this->delivery_number}");
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== DocumentStatus::Draft) {
            throw new SalesDeliveryNotEditableException($this);
        }
    }

    public function totalCogs(): string
    {
        return $this->items->reduce(fn (string $carry, SalesDeliveryItem $item) => bcadd($carry, (string) $item->cogs_total, 2), '0');
    }
}
