<?php

namespace App\Services\Purchasing;

use App\Actions\Purchasing\ReceivePurchaseOrderAction;
use App\DTO\Purchasing\ReceivePurchaseOrderBatchData;
use App\DTO\Purchasing\ReceivePurchaseOrderData;
use App\Exceptions\PurchaseOrderReceiptBatchRequiresItemsException;
use App\Exceptions\PurchaseOrderReceiptBatchRequiresAttachmentException;
use App\Models\PurchaseOrderReceiptBatch;
use Illuminate\Support\Facades\DB;

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

            $batch = PurchaseOrderReceiptBatch::create([
                'purchase_order_id' => $data->purchaseOrderId,
                'received_by' => $data->receivedBy,
                'received_at' => now(),
                'attachment_path' => $data->attachmentPath,
            ]);

            foreach ($itemsToReceive as $item) {
                app(ReceivePurchaseOrderAction::class)->handle(new ReceivePurchaseOrderData(
                    purchaseOrderItemId: $item['purchaseOrderItemId'],
                    quantityReceived: $item['quantityReceived'],
                    receivedBy: $data->receivedBy,
                    receiptCondition: $item['receiptCondition'],
                    purchaseOrderReceiptBatchId: $batch->id,
                ));
            }

            return $batch;
        });
    }
}