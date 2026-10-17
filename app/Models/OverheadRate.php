<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class OverheadRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate_per_hour' => 'decimal:2',
            'budgeted_machine_hours' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function components(): HasMany
    {
        return $this->hasMany(OverheadRateComponent::class);
    }

    public function hasBreakdown(): bool
    {
        return $this->budgeted_machine_hours !== null;
    }

    public function budgetedTotal(): string
    {
        return $this->components->reduce(fn (string $carry, OverheadRateComponent $c) => bcadd($carry, (string) $c->amount, 2), '0');
    }
}
