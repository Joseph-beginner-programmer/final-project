<?php

namespace App\Actions\Production;

use App\Actions\Production\Concerns\BuildsWorkOrderPlan;
use App\DTO\Production\CreateWorkOrderData;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkOrderAction
{
    use BuildsWorkOrderPlan;

    public function handle(CreateWorkOrderData $data): WorkOrder
    {
        [$formula, $overheadRate] = $this->validatePlan($data);

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
            $workOrder->planned_overhead_cost = '0';

            $workOrder->wo_number = (string) Str::uuid(); // placeholder; real number needs this row's own id, set below
            $workOrder->save();

            $workOrder->wo_number = sprintf('WO-%s-%06d', $workOrder->created_at->format('Y'), $workOrder->id);
            $workOrder->save();

            $this->copyPlan($workOrder, $formula, $overheadRate, $data);

            return $workOrder;
        });
    }
}
