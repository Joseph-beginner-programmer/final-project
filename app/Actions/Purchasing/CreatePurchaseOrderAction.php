<?php

namespace App\Actions\Purchasing;

use App\DTO\Purchasing\CreatePurchaseOrderData;
use App\Exceptions\InvalidDeliveryDateException;
use App\Exceptions\NonPurchasableProductException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCharge;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePurchaseOrderAction
{
    public function handle(CreatePurchaseOrderData $data): PurchaseOrder
    {
        if ($data->expectedDeliveryDate !== null && $data->expectedDeliveryDate < $data->orderDate) {
            throw new InvalidDeliveryDateException($data->expectedDeliveryDate, $data->orderDate);
        }

        return DB::transaction(function () use ($data) {
            $po = new PurchaseOrder([
                'supplier_id' => $data->supplierId,
                'created_by' => $data->createdBy,
                'order_date' => $data->orderDate,
                'expected_delivery_date' => $data->expectedDeliveryDate,
            ]);
            
            $po->po_number = (string) Str::uuid();
            $po->save();

            $po->po_number = sprintf('PO-%s-%06d', $po->order_date->format('Y'), $po->id);
            $po->save();

            foreach ($data->items as $item) {
                $this->addItem($po, $item);
            }

            foreach ($data->charges as $charge) {
                $poCharge = new PurchaseOrderCharge([
                    'purchase_order_id' => $po->id,
                    'description' => $charge['description'],
                    'amount' => $charge['amount'],
                    'include_in_cost' => $charge['include_in_cost'] ?? true,
                ]);
                $poCharge->save();
            }

            $po->recalculateTotal();

            return $po->load(['items', 'charges']);
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
