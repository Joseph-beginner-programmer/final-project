<?php

namespace App\Actions\Production;

use App\Actions\Production\Concerns\BuildsWorkOrderPlan;
use App\DTO\Production\CreateWorkOrderData;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderNotEditableException;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class UpdateWorkOrderAction
{
    use BuildsWorkOrderPlan;

    public function handle(WorkOrder $workOrder, CreateWorkOrderData $data): WorkOrder
    {
        [$formula, $overheadRate] = $this->validatePlan($data);

        return DB::transaction(function () use ($workOrder, $data, $formula, $overheadRate) {
            $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($workOrder->status !== WorkOrderStatus::Draft) {
                throw new WorkOrderNotEditableException($workOrder);
            }

            $workOrder->fill([
                'production_formula_id' => $formula->id,
                'product_id' => $formula->product_id,
                'work_center_id' => $formula->work_center_id,
                'quantity_target' => $data->quantityTarget,
                'planned_start_date' => $data->plannedStartDate,
                'planned_end_date' => $data->plannedEndDate,
                'planned_machine_hours' => $data->plannedMachineHours,
            ]);

            $this->copyPlan($workOrder, $formula, $overheadRate, $data);

            return $workOrder;
        });
    }
}
