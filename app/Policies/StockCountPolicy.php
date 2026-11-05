<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\StockCount;
use App\Models\User;

class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('warehouse.count');
    }

    public function view(User $user, StockCount $count): bool
    {
        return $user->can('warehouse.count');
    }

    public function create(User $user): bool
    {
        return $user->can('warehouse.count');
    }

    public function update(User $user, StockCount $count): bool
    {
        return $user->can('warehouse.count') && $count->status === DocumentStatus::Draft;
    }

    public function post(User $user, StockCount $count): bool
    {
        return $this->update($user, $count);
    }

    public function cancel(User $user, StockCount $count): bool
    {
        return $this->update($user, $count);
    }
}
