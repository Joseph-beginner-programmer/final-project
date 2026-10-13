<?php

namespace App\Actions\Production\Concerns;

use App\DTO\Production\CreateWorkOrderData;
use App\Enums\EmployeeStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InactiveProductionFormulaException;
use App\Exceptions\InvalidPlannedDateRangeException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\MissingOverheadRateException;
use App\Exceptions\PlannedStartDateInPastException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\Employee;
use App\Models\OverheadRate;
use App\Models\ProductionFormula;
use App\Models\WorkOrder;
use App\Models\WorkOrderLabor;

trait BuildsWorkOrderPlan
{
    /**
     * @return array{0: ProductionFormula, 1: OverheadRate}
     */
    protected function validatePlan(CreateWorkOrderData $data): array
    {
        if ($data->plannedStartDate < now()->toDateString()) {
            throw new PlannedStartDateInPastException($data->plannedStartDate, now()->toDateString());
        }

        if ($data->plannedEndDate < $data->plannedStartDate) {
            throw new InvalidPlannedDateRangeException($data->plannedStartDate, $data->plannedEndDate);
        }

        if (empty($data->labors)) {
            throw new WorkOrderRequiresLaborException();
        }

        $formula = ProductionFormula::findOrFail($data->productionFormulaId);

        if (! $formula->is_active) {
            throw new InactiveProductionFormulaException($formula);
        }

        $overheadRate = $formula->workCenter->overheadRateOn(now());

        if (! $overheadRate) {
            throw new MissingOverheadRateException($formula->workCenter, now()->toDateString());
        }

        return [$formula, $overheadRate];
    }

    protected function copyPlan(WorkOrder $workOrder, ProductionFormula $formula, OverheadRate $overheadRate, CreateWorkOrderData $data): void
    {
        $workOrder->overhead_rate = $overheadRate->rate_per_hour;
        $workOrder->planned_overhead_cost = bcmul($data->plannedMachineHours, (string) $overheadRate->rate_per_hour, 2);
        $workOrder->save();

        $workOrder->materials()->delete();
        foreach ($formula->items as $item) {
            $workOrder->materials()->create([
                'product_id' => $item->material_product_id,
                'quantity_planned' => $formula->scaleMaterialQuantity((string) $item->quantity, $data->quantityTarget),
            ]);
        }

        $workOrder->labors()->delete();
        foreach ($data->labors as $labor) {
            $this->allocateLabor($workOrder, $labor);
        }
    }

    /**
     * @param  array{employeeId: int, plannedHours: string}  $labor
     */
    private function allocateLabor(WorkOrder $workOrder, array $labor): void
    {
        $employee = Employee::findOrFail($labor['employeeId']);

        if ($employee->status !== EmployeeStatus::Active) {
            throw new InactiveEmployeeException($employee);
        }

        $rate = $employee->laborRateOn(now());

        if (! $rate) {
            throw new MissingLaborRateException($employee, now()->toDateString());
        }

        $row = new WorkOrderLabor([
            'work_order_id' => $workOrder->id,
            'employee_id' => $employee->id,
            'planned_hours' => $labor['plannedHours'],
        ]);
        $row->hourly_rate = $rate->hourly_rate;
        $row->planned_cost = bcmul($labor['plannedHours'], (string) $rate->hourly_rate, 2);
        $row->save();
    }
}
