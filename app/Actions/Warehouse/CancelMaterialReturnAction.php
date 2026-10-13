<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Models\MaterialReturn;
use Illuminate\Support\Facades\DB;

class CancelMaterialReturnAction
{
    public function handle(MaterialReturn $return): MaterialReturn
    {
        return DB::transaction(function () use ($return) {
            $return = MaterialReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->ensureIsDraft();
            $return->transitionTo(DocumentStatus::Cancelled);

            return $return;
        });
    }
}
