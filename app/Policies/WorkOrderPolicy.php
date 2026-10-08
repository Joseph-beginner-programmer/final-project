<?php

namespace App\Policies;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('production.view');
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.view');
    }

    public function create(User $user): bool
    {
        return $user->can('production.create');
    }

    /**
     * <<extend>> Mencetak Perintah Kerja — the paper copy is how the floor receives a WO, so it exists
     * once the WO is released (never for a Draft plan or a Cancelled order).
     */
    public function print(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.view')
            && ! in_array($workOrder->status, [WorkOrderStatus::Draft, WorkOrderStatus::Cancelled], true);
    }

    public function release(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.create')
            && $workOrder->status === WorkOrderStatus::Draft;
    }
}
