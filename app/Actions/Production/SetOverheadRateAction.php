<?php

namespace App\Actions\Production;

use App\Enums\OverheadCategory;
use App\Exceptions\InvalidOverheadEstimateException;
use App\Exceptions\InvalidRateEffectiveDateException;
use App\Models\OverheadRate;
use App\Models\WorkCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SetOverheadRateAction
{
    /**
     * @param  array<string, string>  $components
     */
    public function handle(WorkCenter $workCenter, array $components, string $budgetedHours, string $effectiveFrom, int $createdBy): OverheadRate
    {
        $applicable = $workCenter->overheadCategories();
        $amounts = [];
        foreach (OverheadCategory::cases() as $category) {
            $amount = $components[$category->value] ?? '0';
            $amount = $amount === '' ? '0' : $amount;
            if (! is_numeric($amount) || bccomp((string) $amount, '0', 2) < 0) {
                throw new InvalidOverheadEstimateException(InvalidOverheadEstimateException::NEGATIVE_AMOUNT);
            }
            if (! in_array($category, $applicable, true)) {
                if (bccomp((string) $amount, '0', 2) > 0) {
                    throw new InvalidOverheadEstimateException(InvalidOverheadEstimateException::CATEGORY_NOT_USED, $category->label());
                }

                continue;
            }
            $amounts[$category->value] = (string) $amount;
        }

        if (! is_numeric($budgetedHours) || bccomp($budgetedHours, '0', 2) <= 0) {
            throw new InvalidOverheadEstimateException(InvalidOverheadEstimateException::NO_HOURS);
        }

        $total = array_reduce($amounts, fn (string $carry, string $amount) => bcadd($carry, $amount, 2), '0');
        if (bccomp($total, '0', 2) <= 0) {
            throw new InvalidOverheadEstimateException(InvalidOverheadEstimateException::NO_COSTS);
        }

        return DB::transaction(function () use ($workCenter, $amounts, $total, $budgetedHours, $effectiveFrom, $createdBy) {
            WorkCenter::whereKey($workCenter->id)->lockForUpdate()->first();

            $latest = $workCenter->overheadRates()->orderByDesc('effective_from')->first();

            if ($latest && $effectiveFrom <= $latest->effective_from->toDateString()) {
                throw new InvalidRateEffectiveDateException($effectiveFrom, $latest->effective_from->toDateString(), "work center {$workCenter->code}");
            }

            if ($latest && $latest->effective_to === null) {
                $latest->effective_to = Carbon::parse($effectiveFrom)->subDay();
                $latest->save();
            }

            $rate = $workCenter->overheadRates()->create([
                'rate_per_hour' => self::rateFor($total, $budgetedHours),
                'budgeted_machine_hours' => $budgetedHours,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'created_by' => $createdBy,
            ]);

            foreach ($amounts as $category => $amount) {
                if (bccomp($amount, '0', 2) > 0) {
                    $rate->components()->create(['category' => $category, 'amount' => $amount]);
                }
            }

            return $rate;
        });
    }

    public static function rateFor(string $total, string $hours): string
    {
        return bcdiv(bcadd(bcdiv($total, $hours, 4), '0.005', 4), '1', 2);
    }
}
