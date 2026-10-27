<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PurchaseOrderNotCancellableException;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class CancelPurchaseOrderAction
{
    public function __construct(private ResolvePurchaseRequisitionAction $requisitions) {}

    public function handle(PurchaseOrder $po, int $cancelledBy, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $cancelledBy, $reason) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($po->items()->where('quantity_received', '>', 0)->exists()) {
                throw new PurchaseOrderNotCancellableException($po);
            }

            $po->transitionTo(PurchaseOrderStatus::Cancelled);
            $po->cancelled_by = $cancelledBy;
            $po->cancelled_at = now();
            $po->cancel_reason = $reason;
            $po->save();

            $this->requisitions->reopenForRejectedOrder($po);

            return $po;
        });
    }
}
