<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gudang — Mencatat Pengeluaran Barang: materials issued from the warehouse to a Work Order.
 * Draft → Posted: stock only leaves (FIFO, one stock movement per lot) when the issue is posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number')->unique();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // set on posting — a draft hasn't been issued yet
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('total_cost', 12, 2)->unsigned()->default(0);        // Σ FIFO movements, set on posting
            $table->decimal('quantity_consumed', 12, 2)->unsigned()->default(0); // charged to production results later
            $table->text('note')->nullable();                                    // required for materials outside the WO plan
            $table->unique(['material_issue_id', 'product_id'], 'material_issue_items_issue_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issue_items');
        Schema::dropIfExists('material_issues');
    }
};
