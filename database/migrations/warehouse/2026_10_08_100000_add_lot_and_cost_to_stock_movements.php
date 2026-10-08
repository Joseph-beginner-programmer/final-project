<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FIFO costing: every stock movement belongs to exactly one inventory lot and carries its cost.
 * An OUT that spans several lots becomes several movement rows (ERD: "satu baris OUT untuk setiap lot").
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createMissingPurchaseLots();

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->renameColumn('amount', 'quantity'); // match the ERD's name
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('inventory_lot_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_cost', 12, 2)->unsigned()->default(0)->after('quantity');
            $table->decimal('total_value', 12, 2)->unsigned()->default(0)->after('unit_cost');
        });

        // every existing movement is a purchase receipt; its lot is the one sourced from that receipt
        DB::table('stock_movements as m')
            ->join('inventory_lots as l', function ($join) {
                $join->on('l.source_id', '=', 'm.reference_id')
                    ->where('l.source_type', 'purchase_order_receipt')
                    ->where('m.reference_type', 'purchase_order_receipt');
            })
            ->orderBy('m.id')
            ->select('m.id', 'm.quantity', 'l.id as lot_id', 'l.unit_cost')
            ->each(function ($row) {
                DB::table('stock_movements')->where('id', $row->id)->update([
                    'inventory_lot_id' => $row->lot_id,
                    'unit_cost' => $row->unit_cost,
                    'total_value' => bcmul((string) $row->quantity, (string) $row->unit_cost, 2),
                ]);
            });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('inventory_lot_id')->nullable(false)->change();
        });
    }

    /**
     * Purchase receipts recorded before lots existed (Sessions 8–12) never got a lot, so their quantity
     * sat in products.current_stock but was invisible to FIFO. Recreate each lot exactly as receiving
     * would have. Safe only while nothing has been issued yet — guarded below; finds nothing on a fresh DB.
     */
    private function createMissingPurchaseLots(): void
    {
        $missing = DB::table('purchase_order_receipts as r')
            ->leftJoin('inventory_lots as l', function ($join) {
                $join->on('l.source_id', '=', 'r.id')->where('l.source_type', 'purchase_order_receipt');
            })
            ->whereNull('l.id')
            ->join('purchase_order_items as i', 'i.id', '=', 'r.purchase_order_item_id')
            ->leftJoin('purchase_order_receipt_batches as b', 'b.id', '=', 'r.purchase_order_receipt_batch_id')
            ->orderBy('r.id')
            ->select('r.id', 'r.quantity_received', 'r.created_at', 'i.product_id', 'i.unit_price', 'b.received_at')
            ->get();

        if ($missing->isEmpty()) {
            return;
        }

        if (DB::table('stock_movements')->where('direction', 'out')->exists()) {
            throw new RuntimeException('Cannot recreate missing lots after stock has been issued — remaining quantities would be wrong.');
        }

        foreach ($missing as $receipt) {
            $receivedAt = $receipt->received_at ?? $receipt->created_at;

            $id = DB::table('inventory_lots')->insertGetId([
                'lot_number' => (string) Str::uuid(),
                'product_id' => $receipt->product_id,
                'source_type' => 'purchase_order_receipt',
                'source_id' => $receipt->id,
                'quantity_initial' => $receipt->quantity_received,
                'quantity_remaining' => $receipt->quantity_received,
                'unit_cost' => $receipt->unit_price,
                'value_remaining' => bcmul((string) $receipt->quantity_received, (string) $receipt->unit_price, 2),
                'received_at' => $receivedAt,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);

            DB::table('inventory_lots')->where('id', $id)->update([
                'lot_number' => sprintf('LOT-%s-%06d', Carbon::parse($receivedAt)->format('Y'), $id),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_lot_id');
            $table->dropColumn(['unit_cost', 'total_value']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->renameColumn('quantity', 'amount');
        });
        // recreated lots are kept: they're correct data, not schema
    }
};
