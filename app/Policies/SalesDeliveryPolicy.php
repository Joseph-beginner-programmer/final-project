<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\SalesDelivery;
use App\Models\User;

class SalesDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('warehouse.deliver');
    }

    public function view(User $user, SalesDelivery $delivery): bool
    {
        return $user->can('warehouse.deliver');
    }

    public function create(User $user): bool
    {
        return $user->can('warehouse.deliver');
    }

    public function update(User $user, SalesDelivery $delivery): bool
    {
        return $user->can('warehouse.deliver') && $delivery->status === DocumentStatus::Draft;
    }

    public function post(User $user, SalesDelivery $delivery): bool
    {
        return $this->update($user, $delivery);
    }

    public function cancel(User $user, SalesDelivery $delivery): bool
    {
        return $this->update($user, $delivery);
    }
}
