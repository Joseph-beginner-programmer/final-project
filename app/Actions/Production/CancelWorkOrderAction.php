<?php

namespace App\Actions\Production;

use App\Enums\DocumentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaterialIssue;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class CancelWorkOrderAction
{
    public function handle(WorkOrder $workOrder, int $userId, string $reason): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $userId, $reason) {
            $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            $workOrder->cancelled_by = $userId;
            $workOrder->cancelled_at = now();
            $workOrder->cancel_reason = $reason;
            $workOrder->transitionTo(WorkOrderStatus::Cancelled);

            MaterialIssue::where('work_order_id', $workOrder->id)
                ->where('status', DocumentStatus::Draft->value)
                ->lockForUpdate()
                ->get()
                ->each(fn (MaterialIssue $issue) => $issue->transitionTo(DocumentStatus::Cancelled));

            return $workOrder;
        });
    }
}
