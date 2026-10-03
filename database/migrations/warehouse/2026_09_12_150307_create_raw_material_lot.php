<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_receipt_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('remaining_stock', 12, 2)->unsigned();
            $table->timestamps();

            $table->index(['product_id', 'remaining_stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_lots');
    }
};
