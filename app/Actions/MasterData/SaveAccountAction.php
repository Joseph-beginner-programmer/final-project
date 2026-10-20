<?php

namespace App\Actions\MasterData;

use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Models\Account;

class SaveAccountAction
{
    /**
     * @param  array{account_code: string, account_name: string, type: AccountType, system_role: ?AccountSystemRole, is_active: bool}  $fields
     */
    public function handle(array $fields, ?Account $account = null): Account
    {
        $type = $fields['type'];

        if ($account) {
            $account->update($fields);
            $account->normal_balance = $type->normalBalance();
            $account->save();

            return $account;
        }

        $account = new Account($fields);
        $account->normal_balance = $type->normalBalance();
        $account->save();

        return $account;
    }
}
