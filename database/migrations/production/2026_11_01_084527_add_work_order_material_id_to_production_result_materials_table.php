<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->dropForeign(['material_issue_item_id']);
        });

        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->foreignId('material_issue_item_id')->nullable()->change();
            $table->foreign('material_issue_item_id')->references('id')->on('material_issue_items')->restrictOnDelete();

            $table->foreignId('work_order_material_id')->nullable()->after('material_issue_item_id')
                ->constrained()->restrictOnDelete();
            $table->decimal('quantity_returned', 12, 2)->default(0)->after('quantity');
            $table->unique(['production_result_id', 'work_order_material_id'], 'production_result_materials_result_wo_material_unique');
        });
    }

    public function down(): void
    {
        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->dropUnique('production_result_materials_result_wo_material_unique');
            $table->dropColumn('quantity_returned');
            $table->dropForeign(['work_order_material_id']);
            $table->dropColumn('work_order_material_id');

            $table->dropForeign(['material_issue_item_id']);
        });

        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->foreignId('material_issue_item_id')->nullable(false)->change();
            $table->foreign('material_issue_item_id')->references('id')->on('material_issue_items')->restrictOnDelete();
        });
    }
};
