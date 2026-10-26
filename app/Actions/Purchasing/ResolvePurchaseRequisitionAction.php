<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseRequisitionStatus;
use App\Exceptions\InvalidPurchaseRequisitionException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use Illuminate\Support\Facades\DB;

class ResolvePurchaseRequisitionAction
{
    public function cancel(PurchaseRequisition $requisition, int $userId): PurchaseRequisition
    {
        return $this->close($requisition, PurchaseRequisitionStatus::Cancelled, $userId);
    }

    public function reject(PurchaseRequisition $requisition, string $reason, int $userId): PurchaseRequisition
    {
        if (blank($reason)) {
            throw new InvalidPurchaseRequisitionException(InvalidPurchaseRequisitionException::REASON_REQUIRED);
        }

        return $this->close($requisition, PurchaseRequisitionStatus::Rejected, $userId, $reason);
    }

    /**
     * @param  list<int>  $requisitionIds
     */
    public function markProcessed(array $requisitionIds, PurchaseOrder $po, int $userId): void
    {
        foreach (PurchaseRequisition::whereIn('id', $requisitionIds)->orderBy('id')->lockForUpdate()->get() as $requisition) {
            $requisition->ensureIsSubmitted();
            $requisition->status = PurchaseRequisitionStatus::Processed;
            $requisition->purchase_order_id = $po->id;
            $requisition->processed_by = $userId;
            $requisition->processed_at = now();
            $requisition->save();
        }
    }

    public function reopenForRejectedOrder(PurchaseOrder $po): void
    {
        PurchaseRequisition::where('purchase_order_id', $po->id)
            ->where('status', PurchaseRequisitionStatus::Processed->value)
            ->update(['status' => PurchaseRequisitionStatus::Submitted->value, 'updated_at' => now()]);
    }

    public function restoreForReopenedOrder(PurchaseOrder $po): void
    {
        PurchaseRequisition::where('purchase_order_id', $po->id)
            ->where('status', PurchaseRequisitionStatus::Submitted->value)
            ->update(['status' => PurchaseRequisitionStatus::Processed->value, 'updated_at' => now()]);
    }

    protected function close(PurchaseRequisition $requisition, PurchaseRequisitionStatus $status, int $userId, ?string $reason = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $status, $userId, $reason) {
            $requisition = PurchaseRequisition::whereKey($requisition->id)->lockForUpdate()->firstOrFail();
            $requisition->ensureIsSubmitted();
            $requisition->status = $status;
            $requisition->rejection_reason = $reason;
            $requisition->processed_by = $userId;
            $requisition->processed_at = now();
            $requisition->save();

            return $requisition;
        });
    }
}
