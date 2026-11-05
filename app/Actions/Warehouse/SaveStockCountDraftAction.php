<?php

namespace App\Actions\Warehouse;

use App\Models\StockCount;
use Illuminate\Support\Facades\DB;

class SaveStockCountDraftAction
{
    /**
     * @param  array<int, string>  $countedByItemId  stock_count_item id => counted quantity
     */
    public function handle(StockCount $count, array $countedByItemId): StockCount
    {
        return DB::transaction(function () use ($count, $countedByItemId) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $count->ensureIsDraft();

            foreach ($count->items as $item) {
                if (! array_key_exists($item->id, $countedByItemId)) {
                    continue;
                }

                $value = trim($countedByItemId[$item->id]);
                $item->counted_quantity = $value === '' ? null : $value;
                $item->save();
            }

            return $count->load('items.product');
        });
    }
}
