<?php

namespace App\Services\Purchasing;

use App\Actions\Purchasing\ReceivePurchaseOrderAction;
use App\DTO\Purchasing\ReceivePurchaseOrderBatchData;
use App\DTO\Purchasing\ReceivePurchaseOrderData;
use App\Exceptions\PurchaseOrderReceiptBatchRequiresItemsException;
use App\Exceptions\PurchaseOrderReceiptBatchRequiresAttachmentException;
use App\Models\PurchaseOrderReceiptBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceivePurchaseOrderBatchService
{
    public function handle(ReceivePurchaseOrderBatchData $data): PurchaseOrderReceiptBatch
    {
        return DB::transaction(function () use ($data) {
            $itemsToReceive = collect($data->items)->filter(
                fn ($item) => bccomp($item['quantityReceived'] ?: '0', '0', 2) > 0
            );

            if ($itemsToReceive->isEmpty()) {
                throw new PurchaseOrderReceiptBatchRequiresItemsException();
            }

            if (blank($data->attachmentPath)) {
                throw new PurchaseOrderReceiptBatchRequiresAttachmentException();
            }

            $batch = new PurchaseOrderReceiptBatch([
                'purchase_order_id' => $data->purchaseOrderId,
                'received_by' => $data->receivedBy,
                'received_at' => now(),
                'attachment_path' => $data->attachmentPath,
            ]);
            $batch->receipt_number = (string) Str::uuid(); // placeholder; real number needs this row's own id, set below
            $batch->save();

            $batch->receipt_number = sprintf('RCV-%s-%06d', $batch->received_at->format('Y'), $batch->id);
            $batch->save();

            foreach ($itemsToReceive as $item) {
                app(ReceivePurchaseOrderAction::class)->handle(new ReceivePurchaseOrderData(
                    purchaseOrderItemId: $item['purchaseOrderItemId'],
                    quantityReceived: $item['quantityReceived'],
                    receivedBy: $data->receivedBy,
                    purchaseOrderReceiptBatchId: $batch->id,
                ));
            }

            return $batch;
        });
    }
}