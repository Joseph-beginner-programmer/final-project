<?php

namespace App\Actions\Production;

use App\DTO\Production\ProductionResultData;
use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InvalidProductionResultException;
use App\Exceptions\MissingLaborRateException;
use App\Models\Employee;
use App\Models\Product;
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

        $firstIssuedAt = $workOrder->materialIssues()->where('status', DocumentStatus::Posted->value)->min('issued_at');
        if ($firstIssuedAt && $date->isBefore(Carbon::parse($firstIssuedAt)->startOfDay())) {
            throw new InvalidProductionResultException(InvalidProductionResultException::DATE_BEFORE_ISSUE, Carbon::parse($firstIssuedAt)->format('d M Y'));
        }

        $issued = $workOrder->issuedQuantities();
        foreach ($data->materialsUsed as $productId => $used) {
            if (bccomp($used ?: '0', $issued[$productId] ?? '0', 2) > 0) {
                $name = Product::find($productId)?->product_name ?? "#{$productId}";
                throw new InvalidProductionResultException(InvalidProductionResultException::USED_MORE_THAN_ISSUED, $name);
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
