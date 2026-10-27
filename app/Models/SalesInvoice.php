<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidSalesInvoiceException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property SalesInvoiceStatus $status
 */
#[Guarded(['id', 'invoice_number', 'status', 'amount_paid', 'posted_by', 'posted_at'])]
class SalesInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SalesInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'goods_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'charges_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesDelivery(): BelongsTo
    {
        return $this->belongsTo(SalesDelivery::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class);
    }

    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function balance(): string
    {
        $settled = bcadd((string) $this->returned_amount, bcsub((string) $this->amount_paid, (string) $this->refunded_amount, 2), 2);

        return bcsub((string) $this->total_amount, $settled, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [SalesInvoiceStatus::Unpaid, SalesInvoiceStatus::PartiallyPaid], true)
            && $this->due_date->lt(now()->startOfDay());
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== SalesInvoiceStatus::Draft) {
            throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::NOT_DRAFT, $this->invoice_number);
        }
    }

    public function syncPaymentStatus(): void
    {
        $paid = (string) $this->payments()->where('status', DocumentStatus::Posted->value)->sum('amount');
        $this->amount_paid = $paid;
        $this->status = match (true) {
            bccomp($this->balance(), '0', 2) <= 0 => SalesInvoiceStatus::Paid,
            bccomp(bcadd($paid, (string) $this->returned_amount, 2), '0', 2) <= 0 => SalesInvoiceStatus::Unpaid,
            default => SalesInvoiceStatus::PartiallyPaid,
        };
        $this->save();
    }
}
