<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Models\SalesDelivery;
use Illuminate\Support\Facades\DB;

class CancelSalesDeliveryAction
{
    public function handle(SalesDelivery $delivery): SalesDelivery
    {
        return DB::transaction(function () use ($delivery) {
            $delivery = SalesDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $delivery->ensureIsDraft();
            $delivery->transitionTo(DocumentStatus::Cancelled);

            return $delivery;
        });
    }
}
