<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use App\Enums\PurchaseOrderStatus; 
use Illuminate\Support\Facades\DB;

class ReopenPurchaseOrderAction
{
    public function handle(PurchaseOrder $po) {
        DB::transaction(function () use ($po) {
            $po->transitionTo(PurchaseOrderStatus::Draft);
            $po->rejected_by = null;
            $po->rejected_at = null;
            $po->rejection_reason = null;
            $po->save();
        }); 
        
        return $po;
    }
}