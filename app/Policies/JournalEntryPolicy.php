<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('journal.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('journal.manage');
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->can('journal.manage');
    }
}
