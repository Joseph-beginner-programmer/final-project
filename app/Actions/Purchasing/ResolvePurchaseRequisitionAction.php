<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseRequisitionStatus;
use App\Exceptions\InvalidPurchaseRequisitionException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
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
     * @param  list<int>  $productIds
     */
    public function markProcessed(array $requisitionIds, PurchaseOrder $po, int $userId, array $productIds): void
    {
        foreach (PurchaseRequisition::whereIn('id', $requisitionIds)->with('items.purchaseOrder')->orderBy('id')->lockForUpdate()->get() as $requisition) {
            $requisition->ensureIsSubmitted();

            foreach ($requisition->items as $item) {
                if (in_array($item->product_id, $productIds, true) && ! $item->isFulfilled()) {
                    $item->update(['purchase_order_id' => $po->id]);
                }
            }

            if ($requisition->refresh()->load('items.purchaseOrder')->isFullyFulfilled()) {
                $requisition->status = PurchaseRequisitionStatus::Processed;
                $requisition->purchase_order_id = $po->id;
                $requisition->processed_by = $userId;
                $requisition->processed_at = now();
                $requisition->save();
            }
        }
    }

    public function reopenForRejectedOrder(PurchaseOrder $po): void
    {
        $this->resyncRequisitionsLinkedTo($po);
    }

    public function restoreForReopenedOrder(PurchaseOrder $po): void
    {
        $this->resyncRequisitionsLinkedTo($po);
    }

    protected function resyncRequisitionsLinkedTo(PurchaseOrder $po): void
    {
        $requisitionIds = PurchaseRequisitionItem::where('purchase_order_id', $po->id)
            ->pluck('purchase_requisition_id')
            ->unique();

        foreach (PurchaseRequisition::whereIn('id', $requisitionIds)->with('items.purchaseOrder')->lockForUpdate()->get() as $requisition) {
            $fulfilled = $requisition->isFullyFulfilled();

            if ($fulfilled && $requisition->status !== PurchaseRequisitionStatus::Processed) {
                $requisition->status = PurchaseRequisitionStatus::Processed;
                $requisition->save();
            } elseif (! $fulfilled && $requisition->status === PurchaseRequisitionStatus::Processed) {
                $requisition->status = PurchaseRequisitionStatus::Submitted;
                $requisition->save();
            }
        }
    }

    protected function close(PurchaseRequisition $requisition, PurchaseRequisitionStatus $status, int $userId, ?string $reason = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $status, $userId, $reason) {
            $requisition = PurchaseRequisition::whereKey($requisition->id)->with('items.purchaseOrder')->lockForUpdate()->firstOrFail();
            $requisition->ensureIsSubmitted();
            $requisition->ensureNoneFulfilled();
            $requisition->status = $status;
            $requisition->rejection_reason = $reason;
            $requisition->processed_by = $userId;
            $requisition->processed_at = now();
            $requisition->save();

            return $requisition;
        });
    }
}
