<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A work order's plan is copied from its formula at creation (work center + scaled materials),
 * so editing a formula later can never change an existing WO — same idea as the copied rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('work_center_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
        });

        // backfill any existing WOs from their formula, then require it
        DB::table('work_orders')
            ->join('production_formulas', 'production_formulas.id', '=', 'work_orders.production_formula_id')
            ->orderBy('work_orders.id')
            ->select('work_orders.id', 'production_formulas.work_center_id')
            ->each(fn ($row) => DB::table('work_orders')->where('id', $row->id)->update(['work_center_id' => $row->work_center_id]));

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('work_center_id')->nullable(false)->change();
        });

        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_planned', 12, 2)->unsigned();
            $table->unique(['work_order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_materials');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_center_id');
        });
    }
};
