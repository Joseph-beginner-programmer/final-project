<?php

namespace App\Actions\Production;

use App\DTO\Production\ProductionResultData;
use App\Enums\EmployeeStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InvalidProductionResultException;
use App\Exceptions\MissingLaborRateException;
use App\Models\Employee;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;

class ValidateProductionResultAction
{
    public function handle(WorkOrder $workOrder, ProductionResultData $data): void
    {
        if (bccomp($data->quantityGood ?: '0', '0', 2) <= 0) {
            throw new InvalidProductionResultException(InvalidProductionResultException::NO_GOOD_OUTPUT);
        }

        $date = Carbon::parse($data->productionDate)->startOfDay();
        if ($date->isAfter(today())) {
            throw new InvalidProductionResultException(InvalidProductionResultException::DATE_IN_FUTURE);
        }

        if ($workOrder->released_at && $date->isBefore($workOrder->released_at->copy()->startOfDay())) {
            throw new InvalidProductionResultException(InvalidProductionResultException::DATE_BEFORE_RELEASE, $workOrder->released_at->format('d M Y'));
        }

        foreach ($workOrder->materials()->with('product')->get() as $material) {
            $used = $data->materialsUsed[$material->product_id] ?? '0';
            if (bccomp($used ?: '0', (string) $material->quantity_planned, 2) > 0) {
                throw new InvalidProductionResultException(InvalidProductionResultException::USED_MORE_THAN_PLANNED, $material->product->product_name);
            }

            $returned = $data->materialsReturned[$material->product_id] ?? '0';
            if (bccomp(bcadd($used ?: '0', $returned ?: '0', 2), (string) $material->quantity_planned, 2) > 0) {
                throw new InvalidProductionResultException(InvalidProductionResultException::USED_PLUS_RETURNED_OVER_PLANNED, $material->product->product_name);
            }
        }

        $workers = array_filter($data->laborHours, fn (string $hours) => bccomp($hours ?: '0', '0', 2) > 0);
        if ($workers === []) {
            throw new InvalidProductionResultException(InvalidProductionResultException::NO_WORKERS);
        }

        $plannedIds = $workOrder->labors()->pluck('employee_id')->all();
        foreach (array_keys($workers) as $employeeId) {
            if (in_array($employeeId, $plannedIds, true)) {
                continue;
            }

            $employee = Employee::findOrFail($employeeId);
            if ($employee->status !== EmployeeStatus::Active) {
                throw new InactiveEmployeeException($employee);
            }
            if (! $employee->laborRateOn($date)) {
                throw new MissingLaborRateException($employee, $date->toDateString());
            }
        }
    }
}
