<?php

namespace App\Policies;

use App\Models\User;

class LaborRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('labor-rates.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('labor-rates.manage');
    }
}
