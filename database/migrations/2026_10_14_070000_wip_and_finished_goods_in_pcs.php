<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $wrong = DB::table('products')
            ->whereIn('type', ['wip', 'finished_goods'])
            ->where('unit_of_measure', '!=', 'Pcs');

        $stocked = (clone $wrong)
            ->where(fn ($q) => $q
                ->where('current_stock', '!=', 0)
                ->orWhereExists(fn ($e) => $e->from('stock_movements')->whereColumn('stock_movements.product_id', 'products.id')))
            ->pluck('product_code');

        if ($stocked->isNotEmpty()) {
            throw new RuntimeException('These WIP/finished goods already have stock in another unit: '.$stocked->implode(', '));
        }

        $wrong->update(['unit_of_measure' => 'Pcs', 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
