<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overhead_rates', function (Blueprint $table) {
            $table->decimal('budgeted_machine_hours', 12, 2)->unsigned()->nullable()->after('rate_per_hour');
        });

        Schema::create('overhead_rate_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overhead_rate_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->timestamps();
            $table->unique(['overhead_rate_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overhead_rate_components');

        Schema::table('overhead_rates', function (Blueprint $table) {
            $table->dropColumn('budgeted_machine_hours');
        });
    }
};
