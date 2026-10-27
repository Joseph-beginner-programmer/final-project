<?php

namespace App\Actions\Sales;

use App\Enums\DocumentStatus;
use App\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

class CancelSalesReturnAction
{
    public function handle(SalesReturn $return): SalesReturn
    {
        return DB::transaction(function () use ($return) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->ensureIsDraft();
            $return->transitionTo(DocumentStatus::Cancelled);

            return $return;
        });
    }
}
