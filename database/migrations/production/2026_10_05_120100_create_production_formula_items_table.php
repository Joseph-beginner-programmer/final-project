<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_formula_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_formula_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            // explicit name: the auto-generated one exceeds MySQL's 64-char identifier limit
            $table->unique(['production_formula_id', 'material_product_id'], 'formula_items_formula_material_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_formula_items');
    }
};
