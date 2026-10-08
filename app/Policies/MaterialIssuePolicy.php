<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\MaterialIssue;
use App\Models\User; 

class MaterialIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('warehouse.issue');
    }

    public function view(User $user, MaterialIssue $issue): bool
    {
        return $user->can('warehouse.issue');
    }

    public function create(User $user): bool
    {
        return $user->can('warehouse.issue');
    }

    public function update(User $user, MaterialIssue $issue): bool
    {
        return $user->can('warehouse.issue') && $issue->status === DocumentStatus::Draft;
    }

    public function post(User $user, MaterialIssue $issue): bool
    {
        return $this->update($user, $issue);
    }

    public function cancel(User $user, MaterialIssue $issue): bool
    {
        return $this->update($user, $issue);
    }
}
