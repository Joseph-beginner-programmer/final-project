<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class RejectPurchaseOrderAction
{
    public function handle(
        PurchaseOrder $po, 
        int $rejectedBy,
        string $rejectionReason 
    ) {
        DB::transaction(function() use ($po, $rejectedBy, $rejectionReason) {
            $po->transitionTo(PurchaseOrderStatus::Rejected);
            $po->rejected_by = $rejectedBy;
            $po->rejected_at = now();
            $po->rejection_reason = $rejectionReason;
            $po->save();
        });

        return $po;
    }
}