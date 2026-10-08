<?php

namespace App\Actions\Production;

use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class ReleaseWorkOrderAction
{
    public function handle(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            // re-read under a lock: the in-memory copy may be stale if someone else released it meanwhile
            $locked = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            // checked again here even though creation already requires workers — Release must hold no matter how the WO was made
            if ($locked->labors()->doesntExist()) {
                throw new WorkOrderRequiresLaborException($locked->id);
            }

            $locked->transitionTo(WorkOrderStatus::Released);

            return $locked;
        });
    }
}
