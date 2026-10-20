<?php

namespace App\Models;

use App\Enums\JournalEntryType;
use App\Enums\JournalStatus;
use App\Exceptions\InvalidJournalStatusTransitionException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Guarded(['id', 'journal_number', 'status'])]
class JournalEntry extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'entry_type' => JournalEntryType::class,
            'status' => JournalStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transitionTo(JournalStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidJournalStatusTransitionException($this->status, $target, $this->journal_number);
        }

        $this->status = $target;
        $this->save();
    }

    public function totalDebit(): string
    {
        return $this->lines->reduce(fn (string $carry, JournalEntryLine $line) => bcadd($carry, (string) $line->debit, 2), '0');
    }
}
