<?php

namespace App\Actions\Production;

use App\DTO\Production\CreateWorkOrderData;
use App\Enums\EmployeeStatus;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InactiveProductionFormulaException;
use App\Exceptions\InvalidPlannedDateRangeException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\MissingOverheadRateException;
use App\Exceptions\PlannedStartDateInPastException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\Employee;
use App\Models\ProductionFormula;
use App\Models\WorkOrder;
use App\Models\WorkOrderLabor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkOrderAction
{
    public function handle(CreateWorkOrderData $data): WorkOrder
    {
        // a plan can't start in the past (ISO Y-m-d strings compare correctly as plain strings)
        if ($data->plannedStartDate < now()->toDateString()) {
            throw new PlannedStartDateInPastException($data->plannedStartDate, now()->toDateString());
        }

        if ($data->plannedEndDate < $data->plannedStartDate) {
            throw new InvalidPlannedDateRangeException($data->plannedStartDate, $data->plannedEndDate);
        }

        // <<include>> Mengalokasi Biaya Tenaga Kerja: allocating labor is a mandatory part of creating a WO
        if (empty($data->labors)) {
            throw new WorkOrderRequiresLaborException();
        }

        $formula = ProductionFormula::findOrFail($data->productionFormulaId);

        if (!$formula->is_active) {
            throw new InactiveProductionFormulaException($formula);
        }

        // applied overhead (BOP dibebankan): the work center's rate in effect now, copied onto the WO
        $overheadRate = $formula->workCenter->overheadRateOn(now());

        if (!$overheadRate) {
            throw new MissingOverheadRateException($formula->workCenter, now()->toDateString());
        }

        return DB::transaction(function () use ($data, $formula, $overheadRate) {
            $workOrder = new WorkOrder([
                'production_formula_id' => $formula->id,
                // derived from the formula, never caller-supplied, so a WO can't name a different product than its recipe
                'product_id' => $formula->product_id,
                'work_center_id' => $formula->work_center_id,
                'quantity_target' => $data->quantityTarget,
                'planned_start_date' => $data->plannedStartDate,
                'planned_end_date' => $data->plannedEndDate,
                'planned_machine_hours' => $data->plannedMachineHours,
                'created_by' => $data->createdBy,
            ]);

            // set explicitly rather than relying on the DB default, so the returned model knows its own status
            $workOrder->status = WorkOrderStatus::Draft;
            $workOrder->overhead_rate = $overheadRate->rate_per_hour;
            $workOrder->planned_overhead_cost = bcmul($data->plannedMachineHours, (string) $overheadRate->rate_per_hour, 2);

            $workOrder->wo_number = (string) Str::uuid(); // placeholder; real number needs this row's own id, set below
            $workOrder->save();

            $workOrder->wo_number = sprintf('WO-%s-%06d', $workOrder->created_at->format('Y'), $workOrder->id);
            $workOrder->save();

            // copy the recipe, scaled to the target — later formula edits can't change this WO's plan
            foreach ($formula->items as $item) {
                $workOrder->materials()->create([
                    'product_id' => $item->material_product_id,
                    'quantity_planned' => $formula->scaleMaterialQuantity((string) $item->quantity, $data->quantityTarget),
                ]);
            }

            foreach ($data->labors as $labor) {
                $this->allocateLabor($workOrder, $labor);
            }

            return $workOrder;
        });
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

        // the rate in effect at allocation time, copied onto the row — later rate changes don't rewrite this WO's plan
        $rate = $employee->laborRateOn(now());

        if (!$rate) {
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
