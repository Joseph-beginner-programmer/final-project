<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_labors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_hours', 10, 2)->unsigned();
            $table->decimal('hourly_rate', 12, 2)->unsigned(); // snapshot of labor_rates at allocation time
            $table->decimal('planned_cost', 12, 2)->unsigned();
            $table->unique(['work_order_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_labors');
    }
};
