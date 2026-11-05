<?php

namespace App\Actions\Production;

use App\DTO\Production\ProductionResultData;
use App\Enums\DocumentStatus;
use App\Exceptions\WorkOrderNotRecordableException;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveProductionResultDraftAction
{
    public function __construct(
        private readonly ValidateProductionResultAction $validate,
    ) {}

    public function handle(WorkOrder $workOrder, ProductionResultData $data, int $userId, ?ProductionResult $result = null): ProductionResult
    {
        if ($result) {
            $result->ensureIsDraft();
        } elseif ($workOrder->productionResult()->exists()) {
            throw new WorkOrderNotRecordableException($workOrder, WorkOrderNotRecordableException::ALREADY_RECORDED);
        }

        if (! $workOrder->canRecordResult()) {
            throw new WorkOrderNotRecordableException($workOrder, WorkOrderNotRecordableException::NOT_IN_PROGRESS);
        }

        $this->validate->handle($workOrder, $data);

        return DB::transaction(function () use ($workOrder, $data, $userId, $result) {
            $fields = [
                'production_date' => $data->productionDate,
                'quantity_good' => $data->quantityGood,
                'quantity_reject' => $data->quantityReject ?: '0',
                'reject_reason' => bccomp($data->quantityReject ?: '0', '0', 2) > 0 && filled($data->rejectReason) ? $data->rejectReason : null,
                'machine_hours' => $data->machineHours ?: '0',
                'notes' => filled($data->notes) ? $data->notes : null,
            ];

            if (! $result) {
                $result = new ProductionResult($fields + [
                    'work_order_id' => $workOrder->id,
                    'created_by' => $userId,
                ]);
                $result->status = DocumentStatus::Draft;
                $result->result_number = (string) Str::uuid();
                $result->save();

                $result->result_number = sprintf('RES-%s-%06d', $result->created_at->format('Y'), $result->id);
                $result->save();
            } else {
                $result->update($fields);
            }

            $result->materials()->delete();
            foreach ($workOrder->materials as $material) {
                $used = $data->materialsUsed[$material->product_id] ?? '0';
                $returned = $data->materialsReturned[$material->product_id] ?? '0';

                if (bccomp($used ?: '0', '0', 2) <= 0 && bccomp($returned ?: '0', '0', 2) <= 0) {
                    continue;
                }

                $result->materials()->create([
                    'work_order_material_id' => $material->id,
                    'product_id' => $material->product_id,
                    'quantity' => $used ?: '0',
                    'quantity_returned' => $returned ?: '0',
                ]);
            }

            $result->labors()->delete();
            foreach ($data->laborHours as $employeeId => $hours) {
                if (bccomp($hours ?: '0', '0', 2) > 0) {
                    $result->labors()->create(['employee_id' => $employeeId, 'hours' => $hours]);
                }
            }

            return $result->load(['materials', 'labors']);
        });
    }
}
