<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Models\ProductionReceipt;
use Illuminate\Support\Facades\DB;

class CancelProductionReceiptAction
{
    public function handle(ProductionReceipt $receipt): ProductionReceipt
    {
        return DB::transaction(function () use ($receipt) {
            $receipt = ProductionReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $receipt->ensureIsDraft();
            $receipt->transitionTo(DocumentStatus::Cancelled);

            return $receipt;
        });
    }
}
