<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->string('lot_number')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->decimal('quantity_initial', 12, 2)->unsigned();
            $table->decimal('quantity_remaining', 12, 2)->unsigned();
            $table->decimal('unit_cost', 12, 2)->unsigned();
            $table->decimal('value_remaining', 12, 2)->unsigned();
            $table->dateTime('received_at');
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['product_id', 'received_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
