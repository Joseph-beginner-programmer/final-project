<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.manage');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounts.manage');
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounts.manage');
    }
}
