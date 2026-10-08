<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('formula_code')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->decimal('output_quantity', 12, 2)->unsigned();
            $table->decimal('overhead_rate_per_unit', 12, 2)->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_formulas');
    }
};
