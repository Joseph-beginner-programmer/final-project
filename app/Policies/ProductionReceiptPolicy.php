<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\ProductionReceipt;
use App\Models\User;

class ProductionReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('warehouse.receive');
    }

    public function view(User $user, ProductionReceipt $receipt): bool
    {
        return $user->can('warehouse.receive');
    }

    public function create(User $user): bool
    {
        return $user->can('warehouse.receive');
    }

    public function update(User $user, ProductionReceipt $receipt): bool
    {
        return $user->can('warehouse.receive') && $receipt->status === DocumentStatus::Draft;
    }

    public function post(User $user, ProductionReceipt $receipt): bool
    {
        return $this->update($user, $receipt);
    }

    public function cancel(User $user, ProductionReceipt $receipt): bool
    {
        return $this->update($user, $receipt);
    }
}
