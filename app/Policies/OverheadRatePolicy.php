<?php

namespace App\Policies;

use App\Models\User;

class OverheadRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('overhead.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('overhead.manage');
    }
}
