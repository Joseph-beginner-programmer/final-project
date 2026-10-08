<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_formulas', function (Blueprint $table) {
            $table->foreignId('work_center_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
        });

        // backfill existing formulas: raw → WIP runs on molding, WIP → finished goods on the assembly line
        $molding = DB::table('work_centers')->where('code', 'MOLDING')->value('id');
        $assembly = DB::table('work_centers')->where('code', 'ASSEMBLY')->value('id');

        DB::table('production_formulas')
            ->join('products', 'products.id', '=', 'production_formulas.product_id')
            ->orderBy('production_formulas.id')
            ->select('production_formulas.id', 'products.type')
            ->each(function ($formula) use ($molding, $assembly) {
                DB::table('production_formulas')
                    ->where('id', $formula->id)
                    ->update(['work_center_id' => $formula->type === 'finished_goods' ? $assembly : $molding]);
            });

        Schema::table('production_formulas', function (Blueprint $table) {
            $table->foreignId('work_center_id')->nullable(false)->change();
            // overhead is now a per-machine-hour rate on the work center, not a per-unit rate on the recipe
            $table->dropColumn('overhead_rate_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('production_formulas', function (Blueprint $table) {
            $table->decimal('overhead_rate_per_unit', 12, 2)->unsigned()->default(0)->after('output_quantity');
            $table->dropConstrainedForeignId('work_center_id');
        });
    }
};
