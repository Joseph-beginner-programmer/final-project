<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidStockCountException;
use App\Models\Product;
use App\Models\StockCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStockCountAction
{
    public function handle(int $userId): StockCount
    {
        return DB::transaction(function () use ($userId) {
            $open = StockCount::where('status', DocumentStatus::Draft->value)->first();
            if ($open) {
                throw new InvalidStockCountException(InvalidStockCountException::ALREADY_OPEN, $open->count_number);
            }

            $count = new StockCount([
                'count_date' => now()->toDateString(),
                'created_by' => $userId,
            ]);
            $count->status = DocumentStatus::Draft;
            $count->count_number = (string) Str::uuid();
            $count->save();

            $count->count_number = sprintf('SC-%s-%06d', $count->created_at->format('Y'), $count->id);
            $count->save();

            $products = Product::active()->orderBy('type')->orderBy('product_code')->get();
            foreach ($products as $product) {
                $count->items()->create([
                    'product_id' => $product->id,
                    'system_quantity' => $product->current_stock,
                ]);
            }

            return $count->load('items.product');
        });
    }
}
