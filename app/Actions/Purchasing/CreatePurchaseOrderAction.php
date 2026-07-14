<?php

namespace App\Actions\Purchasing;

use App\DTO\Purchasing\CreatePurchaseOrderData;
use App\Exceptions\NonPurchasableProductException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePurchaseOrderAction
{
    public function handle(CreatePurchaseOrderData $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $po = new PurchaseOrder([
                'supplier_id' => $data->supplierId,
                'created_by' => $data->createdBy,
                'order_date' => $data->orderDate,
                'expected_delivery_date' => $data->expectedDeliveryDate,
            ]);

            // po_number is guarded and derived from the row's own id, so it
            // can't be known before the first insert. Save once with a
            // placeholder to satisfy the NOT NULL + unique constraint, then
            // save again with the real value.
            $po->po_number = (string) Str::uuid();
            $po->save();

            $po->po_number = sprintf('PO-%s-%06d', $po->order_date->format('Y'), $po->id);
            $po->save();

            foreach ($data->items as $item) {
                $this->addItem($po, $item);
            }

            $po->recalculateTotal();

            return $po->load('items');
        });
    }

    /**
     * @param  array{product_id: int, quantity_ordered: string, unit_price: string}  $item
     */
    private function addItem(PurchaseOrder $po, array $item): void
    {
        $product = Product::findOrFail($item['product_id']);

        if (!$product->type->isPurchasable()) {
            throw new NonPurchasableProductException($product);
        }

        $poItem = new PurchaseOrderItem([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity_ordered' => $item['quantity_ordered'],
            'unit_price' => $item['unit_price'],
        ]);

        $poItem->syncSubtotal();
        $poItem->save();
    }
}
