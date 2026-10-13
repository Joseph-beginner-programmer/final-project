<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('production_result_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_expected', 12, 2)->unsigned();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('unit_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('total_value', 12, 2)->unsigned()->default(0);
            $table->decimal('shortage_value', 12, 2)->unsigned()->default(0);
            $table->text('notes')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_receipts');
    }
};
