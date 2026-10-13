<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\ProductionResult;
use App\Models\User;

class ProductionResultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('production.view');
    }

    public function view(User $user, ProductionResult $result): bool
    {
        return $user->can('production.view');
    }

    public function create(User $user): bool
    {
        return $user->can('production.create');
    }

    public function update(User $user, ProductionResult $result): bool
    {
        return $user->can('production.create') && $result->status === DocumentStatus::Draft;
    }

    public function post(User $user, ProductionResult $result): bool
    {
        return $this->update($user, $result);
    }
}
