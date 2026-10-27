<?php

namespace App\Actions\Purchasing;

use App\Enums\DocumentStatus;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;

class CancelPurchaseReturnAction
{
    public function handle(PurchaseReturn $return): PurchaseReturn
    {
        return DB::transaction(function () use ($return) {
            $return = PurchaseReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->ensureIsDraft();
            $return->transitionTo(DocumentStatus::Cancelled);

            return $return;
        });
    }
}
