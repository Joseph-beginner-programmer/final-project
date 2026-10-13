<?php

namespace App\Actions\Production;

use App\Exceptions\InvalidRateEffectiveDateException;
use App\Models\OverheadRate;
use App\Models\WorkCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SetOverheadRateAction
{
    public function handle(WorkCenter $workCenter, string $ratePerHour, string $effectiveFrom, int $createdBy): OverheadRate
    {
        return DB::transaction(function () use ($workCenter, $ratePerHour, $effectiveFrom, $createdBy) {
            WorkCenter::whereKey($workCenter->id)->lockForUpdate()->first();

            $latest = $workCenter->overheadRates()->orderByDesc('effective_from')->first();

            if ($latest && $effectiveFrom <= $latest->effective_from->toDateString()) {
                throw new InvalidRateEffectiveDateException($effectiveFrom, $latest->effective_from->toDateString(), "work center {$workCenter->code}");
            }

            if ($latest && $latest->effective_to === null) {
                $latest->effective_to = Carbon::parse($effectiveFrom)->subDay();
                $latest->save();
            }

            return $workCenter->overheadRates()->create([
                'rate_per_hour' => $ratePerHour,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'created_by' => $createdBy,
            ]);
        });
    }
}
