<?php

namespace App\Actions\Purchasing;

use App\Exceptions\NonPurchasableProductException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

class AddPurchaseOrderItemAction
{
    public function handle(
        PurchaseOrder $po,
        int $productId,
        string $quantityOrdered,
        string $unitPrice,
    ): PurchaseOrderItem {
        $po->ensureIsEditable();

        $product = Product::findOrFail($productId);

        if (!$product->type->isPurchasable()) {
            throw new NonPurchasableProductException($product);
        }

        $item = new PurchaseOrderItem([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantityOrdered,
            'unit_price' => $unitPrice,
        ]);

        $item->syncSubtotal();
        $item->save();

        $po->recalculateTotal();

        return $item;
    }
}
