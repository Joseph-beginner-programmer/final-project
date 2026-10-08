<?php

namespace App\Actions\Employee;

use App\Exceptions\InvalidRateEffectiveDateException;
use App\Models\Employee;
use App\Models\LaborRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rates are never edited in place: a new rate closes the previous one the day before it starts,
 * so every work order can still look up the rate that applied when it was planned.
 */
class SetLaborRateAction
{
    public function handle(Employee $employee, string $hourlyRate, string $effectiveFrom, int $createdBy): LaborRate
    {
        return DB::transaction(function () use ($employee, $hourlyRate, $effectiveFrom, $createdBy) {
            // serialize rate changes per employee, so two concurrent submissions can't both close the same open rate
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            $latest = $employee->laborRates()->orderByDesc('effective_from')->first();

            if ($latest && $effectiveFrom <= $latest->effective_from->toDateString()) {
                throw new InvalidRateEffectiveDateException($effectiveFrom, $latest->effective_from->toDateString(), "employee #{$employee->id}");
            }

            if ($latest && $latest->effective_to === null) {
                $latest->effective_to = Carbon::parse($effectiveFrom)->subDay();
                $latest->save();
            }

            return $employee->laborRates()->create([
                'hourly_rate' => $hourlyRate,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'created_by' => $createdBy,
            ]);
        });
    }
}
