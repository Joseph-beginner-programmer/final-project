<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use App\Enums\PurchaseOrderStatus; 
use Illuminate\Support\Facades\DB;

class ApprovePurchaseOrderAction
{
    public function handle(
        PurchaseOrder $po,
        int $approveBy 
    ) {
        DB::transaction(function() use ($po, $approveBy) {
            $po->transitionTo(PurchaseOrderStatus::Approved);
            $po->approved_by = $approveBy;
            $po->approved_at = now();   
            $po->save();
        });
    
        return $po;
    }
}