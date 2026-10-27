<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\InvalidPurchaseInvoiceException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property PurchaseInvoiceStatus $status
 */
#[Guarded(['id', 'internal_number', 'status', 'amount_paid', 'posted_by', 'posted_at'])]
class PurchaseInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'goods_amount' => 'decimal:2',
            'capitalized_charges' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'receipt_value' => 'decimal:2',
            'price_variance' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receiptBatch(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceiptBatch::class, 'purchase_order_receipt_batch_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceCharge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
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
        return in_array($this->status, [PurchaseInvoiceStatus::Unpaid, PurchaseInvoiceStatus::PartiallyPaid], true)
            && $this->due_date->lt(now()->startOfDay());
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== PurchaseInvoiceStatus::Draft) {
            throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::NOT_DRAFT, $this->internal_number);
        }
    }

    public function syncPaymentStatus(): void
    {
        $paid = (string) $this->payments()->where('status', DocumentStatus::Posted->value)->sum('amount');
        $this->amount_paid = $paid;
        $this->status = match (true) {
            bccomp($this->balance(), '0', 2) <= 0 => PurchaseInvoiceStatus::Paid,
            bccomp(bcadd($paid, (string) $this->returned_amount, 2), '0', 2) <= 0 => PurchaseInvoiceStatus::Unpaid,
            default => PurchaseInvoiceStatus::PartiallyPaid,
        };
        $this->save();
    }
}
