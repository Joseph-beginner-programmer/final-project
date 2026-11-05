<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_return_items', function (Blueprint $table) {
            $table->dropForeign(['material_issue_item_id']);
        });

        Schema::table('material_return_items', function (Blueprint $table) {
            $table->foreignId('material_issue_item_id')->nullable()->change();
            $table->foreign('material_issue_item_id')->references('id')->on('material_issue_items')->restrictOnDelete();

            $table->foreignId('work_order_material_id')->nullable()->after('material_issue_item_id')
                ->constrained()->restrictOnDelete();
            $table->unique(['material_return_id', 'work_order_material_id'], 'material_return_items_return_wo_material_unique');
        });
    }

    public function down(): void
    {
        Schema::table('material_return_items', function (Blueprint $table) {
            $table->dropUnique('material_return_items_return_wo_material_unique');
            $table->dropForeign(['work_order_material_id']);
            $table->dropColumn('work_order_material_id');

            $table->dropForeign(['material_issue_item_id']);
        });

        Schema::table('material_return_items', function (Blueprint $table) {
            $table->foreignId('material_issue_item_id')->nullable(false)->change();
            $table->foreign('material_issue_item_id')->references('id')->on('material_issue_items')->restrictOnDelete();
        });
    }
};
