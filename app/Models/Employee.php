<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property EmployeeStatus $status
 */
#[Guarded(['id', 'employee_code'])]
class Employee extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'hire_date' => 'date',
        ];
    }

    public function laborRates(): HasMany
    {
        return $this->hasMany(LaborRate::class);
    }

    public function workOrderLabors(): HasMany
    {
        return $this->hasMany(WorkOrderLabor::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Active->value);
    }

    /**
     * The rate in effect on $date (today by default): started on/before it, and not yet ended.
     */
    public function laborRateOn(?Carbon $date = null): ?LaborRate
    {
        $day = ($date ?? now())->toDateString();

        return $this->laborRates()
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();
    }
}
