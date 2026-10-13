<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\MaterialReturn;
use App\Models\User;

class MaterialReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('warehouse.return');
    }

    public function view(User $user, MaterialReturn $return): bool
    {
        return $user->can('warehouse.return');
    }

    public function create(User $user): bool
    {
        return $user->can('warehouse.return');
    }

    public function update(User $user, MaterialReturn $return): bool
    {
        return $user->can('warehouse.return') && $return->status === DocumentStatus::Draft;
    }

    public function post(User $user, MaterialReturn $return): bool
    {
        return $this->update($user, $return);
    }

    public function cancel(User $user, MaterialReturn $return): bool
    {
        return $this->update($user, $return);
    }
}
