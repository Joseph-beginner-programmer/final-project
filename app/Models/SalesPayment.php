<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property DocumentStatus $status
 */
#[Guarded(['id', 'payment_number', 'status', 'cancelled_by', 'cancelled_at'])]
class SalesPayment extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'method' => PaymentMethod::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
