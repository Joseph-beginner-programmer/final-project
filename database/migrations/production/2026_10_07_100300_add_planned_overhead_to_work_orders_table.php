<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->decimal('planned_machine_hours', 10, 2)->unsigned()->default(0)->after('planned_end_date');
            $table->decimal('overhead_rate', 12, 2)->unsigned()->default(0)->after('planned_machine_hours'); // snapshot of overhead_rates at creation
            $table->decimal('planned_overhead_cost', 12, 2)->unsigned()->default(0)->after('overhead_rate');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['planned_machine_hours', 'overhead_rate', 'planned_overhead_cost']);
        });
    }
};
