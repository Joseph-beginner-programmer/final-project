<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class ClosePurchaseOrderAction
{
    public function handle(
        PurchaseOrder $po,
        int $closedBy,
        string $closeReason
    ) {
        DB::transaction(function () use ($po, $closedBy, $closeReason) {
            $po->transitionTo(PurchaseOrderStatus::Closed);
            $po->closed_by = $closedBy;
            $po->closed_at = now();
            $po->close_reason = $closeReason;
            $po->save();
        });

        return $po;
    }
}
