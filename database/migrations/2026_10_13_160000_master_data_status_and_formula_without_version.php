<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('selling_price');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('address');
        });

        $duplicates = DB::table('production_formulas')->select('product_id')->groupBy('product_id')->havingRaw('count(*) > 1')->count();
        if ($duplicates > 0) {
            throw new RuntimeException('Some products have more than one formula; merge them before removing versions.');
        }

        Schema::table('production_formulas', function (Blueprint $table) {
            $table->unique('product_id', 'production_formulas_product_unique');
        });

        Schema::table('production_formulas', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'version']);
            $table->dropColumn('version');
        });
    }

    public function down(): void
    {
        Schema::table('production_formulas', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('work_center_id');
        });

        Schema::table('production_formulas', function (Blueprint $table) {
            $table->unique(['product_id', 'version']);
            $table->dropUnique('production_formulas_product_unique');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
