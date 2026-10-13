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

    public function print(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.view')
            && ! in_array($workOrder->status, [WorkOrderStatus::Draft, WorkOrderStatus::Cancelled], true);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.create')
            && $workOrder->status === WorkOrderStatus::Draft;
    }

    public function cancel(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.create')
            && in_array($workOrder->status, [WorkOrderStatus::Draft, WorkOrderStatus::Released], true);
    }

    public function release(User $user, WorkOrder $workOrder): bool
    {
        return $user->can('production.create')
            && $workOrder->status === WorkOrderStatus::Draft;
    }
}
