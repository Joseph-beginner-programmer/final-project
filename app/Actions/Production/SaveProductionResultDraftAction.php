<?php

namespace App\Actions\Production;

use App\DTO\Production\ProductionResultData;
use App\Enums\DocumentStatus;
use App\Exceptions\WorkOrderNotRecordableException;
use App\Models\MaterialIssueItem;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the WO's (single) draft result, or replaces the reported figures of the existing draft.
 * Nothing is costed here — costs are fixed only in PostProductionResultAction.
 */
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
                $result->status = DocumentStatus::Draft; // explicit, so the returned model knows its status
                $result->result_number = (string) Str::uuid(); // placeholder; real number needs this row's own id
                $result->save();

                $result->result_number = sprintf('RES-%s-%06d', $result->created_at->format('Y'), $result->id);
                $result->save();
            } else {
                $result->update($fields);
            }

            // a draft's lines are a working copy — replace them wholesale
            $result->materials()->delete();
            foreach (self::allocate($workOrder->postedIssueItems(), $data->materialsUsed) as [$item, $quantity]) {
                $result->materials()->create([
                    'material_issue_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
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

    /**
     * Spread each product's reported use over its issue lines, oldest issue first, each line up to
     * what it issued. Lines given nothing keep their whole quantity as leftover.
     *
     * @param  Collection<int, MaterialIssueItem>  $issueLines  ordered oldest issue first
     * @param  array<int, string>  $usedByProduct
     * @return list<array{0: MaterialIssueItem, 1: string}>
     */
    public static function allocate(Collection $issueLines, array $usedByProduct): array
    {
        $allocation = [];

        foreach ($usedByProduct as $productId => $used) {
            $stillToCharge = $used ?: '0';

            foreach ($issueLines->where('product_id', $productId) as $item) {
                if (bccomp($stillToCharge, '0', 2) <= 0) {
                    break;
                }

                $take = bccomp((string) $item->quantity, $stillToCharge, 2) <= 0 ? (string) $item->quantity : $stillToCharge;
                $allocation[] = [$item, $take];
                $stillToCharge = bcsub($stillToCharge, $take, 2);
            }
        }

        return $allocation;
    }
}
