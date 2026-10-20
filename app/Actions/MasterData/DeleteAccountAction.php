<?php

namespace App\Actions\MasterData;

use App\Models\Account;

class DeleteAccountAction
{
    public function handle(Account $account): void
    {
        $account->delete();
    }
}
