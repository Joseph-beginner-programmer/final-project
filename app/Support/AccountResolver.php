<?php

namespace App\Support;

use App\Enums\AccountSystemRole;
use App\Exceptions\InvalidJournalEntryException;
use App\Models\Account;

class AccountResolver
{
    public static function forRole(AccountSystemRole $role): Account
    {
        $account = Account::where('system_role', $role->value)->first();

        if (! $account) {
            throw new InvalidJournalEntryException(InvalidJournalEntryException::UNMAPPED_ROLE, $role->label());
        }

        return $account;
    }
}
