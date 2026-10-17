<?php

namespace App\Policies;

use App\Models\ProductionFormula;
use App\Models\User;

class ProductionFormulaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('formulas.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('formulas.manage');
    }

    public function update(User $user, ProductionFormula $formula): bool
    {
        return $user->can('formulas.manage');
    }
}
