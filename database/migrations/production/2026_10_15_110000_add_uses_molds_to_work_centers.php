<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->boolean('uses_molds')->default(false)->after('name');
        });

        DB::table('work_centers')->where('code', 'MOLDING')->update(['uses_molds' => true]);

        $conflicts = DB::table('overhead_rate_components')
            ->join('overhead_rates', 'overhead_rates.id', '=', 'overhead_rate_components.overhead_rate_id')
            ->join('work_centers', 'work_centers.id', '=', 'overhead_rates.work_center_id')
            ->where('work_centers.uses_molds', false)
            ->where('overhead_rate_components.category', 'mold_depreciation')
            ->pluck('work_centers.code');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException('Mold depreciation is recorded for work centers without molds: '.$conflicts->unique()->implode(', '));
        }
    }

    public function down(): void
    {
        Schema::table('work_centers', function (Blueprint $table) {
            $table->dropColumn('uses_molds');
        });
    }
};
