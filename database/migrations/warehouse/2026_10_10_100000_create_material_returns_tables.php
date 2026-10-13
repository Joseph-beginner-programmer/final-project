<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_result_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('material_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_issue_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_expected', 12, 2)->unsigned();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('total_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('variance_value', 12, 2)->default(0);
            $table->unique(['material_return_id', 'material_issue_item_id'], 'material_return_items_return_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_return_items');
        Schema::dropIfExists('material_returns');
    }
};
