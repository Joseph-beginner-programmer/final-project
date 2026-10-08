<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overhead_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->constrained()->restrictOnDelete();
            $table->decimal('rate_per_hour', 12, 2)->unsigned();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['work_center_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overhead_rates');
    }
};
