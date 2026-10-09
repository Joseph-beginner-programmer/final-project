<?php

namespace App\Actions\Production;

use App\Enums\EmployeeStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\MissingOverheadRateException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Release = the plan goes to the floor. The labor and overhead rates are copied again at this
 * moment (decided 2026-10-09): a draft can sit for weeks, and the WO should carry the rates in
 * force on the day it is actually released. From here on the rates are frozen.
 * Material shortages are only warned about in the release dialog, never blocked here — stock
 * isn't reserved, and Material Issue posting is where short stock is enforced.
 */
class ReleaseWorkOrderAction
{
    public function handle(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            // re-read under a lock: the in-memory copy may be stale if someone else released it meanwhile
            $locked = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            $labors = $locked->labors()->with('employee')->get();

            // checked again here even though creation already requires workers — Release must hold no matter how the WO was made
            if ($labors->isEmpty()) {
                throw new WorkOrderRequiresLaborException($locked->id);
            }

            $today = now();

            foreach ($labors as $labor) {
                if ($labor->employee->status !== EmployeeStatus::Active) {
                    throw new InactiveEmployeeException($labor->employee);
                }

                $rate = $labor->employee->laborRateOn($today)
                    ?? throw new MissingLaborRateException($labor->employee, $today->toDateString());

                $labor->hourly_rate = $rate->hourly_rate;
                $labor->planned_cost = bcmul((string) $labor->planned_hours, (string) $rate->hourly_rate, 2);
                $labor->save();
            }

            $overheadRate = $locked->workCenter->overheadRateOn($today)
                ?? throw new MissingOverheadRateException($locked->workCenter, $today->toDateString());

            $locked->overhead_rate = $overheadRate->rate_per_hour;
            $locked->planned_overhead_cost = bcmul((string) $locked->planned_machine_hours, (string) $overheadRate->rate_per_hour, 2);

            $locked->transitionTo(WorkOrderStatus::Released);

            return $locked;
        });
    }
}
