<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_results', function (Blueprint $table) {
            $table->id();
            $table->string('result_number')->unique();
            $table->foreignId('work_order_id')->unique()->constrained()->restrictOnDelete();
            $table->date('production_date');
            $table->decimal('quantity_good', 12, 2)->unsigned();
            $table->decimal('quantity_reject', 12, 2)->unsigned()->default(0);
            $table->text('reject_reason')->nullable();
            $table->decimal('machine_hours', 10, 2)->unsigned();
            $table->decimal('material_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('labor_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('overhead_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('total_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('unit_cost', 12, 2)->unsigned()->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('production_result_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_issue_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('unit_cost', 12, 2)->unsigned()->nullable();
            $table->decimal('total_cost', 12, 2)->unsigned()->nullable();
            $table->unique(['production_result_id', 'material_issue_item_id'], 'production_result_materials_result_item_unique');
        });

        Schema::create('production_result_labors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('hours', 10, 2)->unsigned();
            $table->decimal('hourly_rate', 12, 2)->unsigned()->nullable();
            $table->decimal('total_cost', 12, 2)->unsigned()->nullable();
            $table->unique(['production_result_id', 'employee_id'], 'production_result_labors_result_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_result_labors');
        Schema::dropIfExists('production_result_materials');
        Schema::dropIfExists('production_results');
    }
};
