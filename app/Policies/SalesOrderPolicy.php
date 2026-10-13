<?php

namespace App\Policies;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;

class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.manage');
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return $user->can('sales.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.manage');
    }

    public function update(User $user, SalesOrder $order): bool
    {
        return $user->can('sales.manage') && $order->status === SalesOrderStatus::Draft;
    }

    public function confirm(User $user, SalesOrder $order): bool
    {
        return $this->update($user, $order);
    }

    public function cancel(User $user, SalesOrder $order): bool
    {
        return $user->can('sales.manage')
            && in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true);
    }
}
