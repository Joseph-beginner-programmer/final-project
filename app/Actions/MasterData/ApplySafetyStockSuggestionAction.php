<?php

namespace App\Actions\MasterData;

use App\Models\Product;

/**
 * Writes a calculated safety stock / reorder point suggestion onto the product.
 * The only action allowed to set these two columns - SaveProductAction strips them
 * from its own $fields, so the Products page can only display them, never edit them.
 */
class ApplySafetyStockSuggestionAction
{
    public function handle(Product $product, string $safetyStock, string $reorderPoint): Product
    {
        $product->update([
            'safety_stock' => $safetyStock,
            'reorder_point' => $reorderPoint,
        ]);

        return $product;
    }
}
