<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_return_items', function (Blueprint $table) {
            $table->dropUnique('material_return_items_return_item_unique');
            $table->dropForeign(['material_issue_item_id']);
            $table->dropColumn('material_issue_item_id');
            $table->dropForeign(['work_order_material_id']);
        });
        Schema::table('material_return_items', function (Blueprint $table) {
            $table->foreignId('work_order_material_id')->nullable(false)->change();
            $table->foreign('work_order_material_id')->references('id')->on('work_order_materials')->restrictOnDelete();
        });

        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->dropUnique('production_result_materials_result_item_unique');
            $table->dropForeign(['material_issue_item_id']);
            $table->dropColumn('material_issue_item_id');
            $table->dropForeign(['work_order_material_id']);
        });
        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->foreignId('work_order_material_id')->nullable(false)->change();
            $table->foreign('work_order_material_id')->references('id')->on('work_order_materials')->restrictOnDelete();
        });

        Schema::dropIfExists('material_issue_items');
        Schema::dropIfExists('material_issues');
    }

    public function down(): void
    {
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number')->unique();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('total_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('quantity_consumed', 12, 2)->unsigned()->default(0);
            $table->text('note')->nullable();
            $table->unique(['material_issue_id', 'product_id'], 'material_issue_items_issue_product_unique');
        });

        Schema::table('material_return_items', function (Blueprint $table) {
            $table->dropForeign(['work_order_material_id']);
        });
        Schema::table('material_return_items', function (Blueprint $table) {
            $table->foreignId('work_order_material_id')->nullable()->change();
            $table->foreign('work_order_material_id')->references('id')->on('work_order_materials')->restrictOnDelete();

            $table->foreignId('material_issue_item_id')->nullable()->after('material_return_id')
                ->constrained()->restrictOnDelete();
            $table->unique(['material_return_id', 'material_issue_item_id'], 'material_return_items_return_item_unique');
        });

        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->dropForeign(['work_order_material_id']);
        });
        Schema::table('production_result_materials', function (Blueprint $table) {
            $table->foreignId('work_order_material_id')->nullable()->change();
            $table->foreign('work_order_material_id')->references('id')->on('work_order_materials')->restrictOnDelete();

            $table->foreignId('material_issue_item_id')->nullable()->after('production_result_id')
                ->constrained()->restrictOnDelete();
            $table->unique(['production_result_id', 'material_issue_item_id'], 'production_result_materials_result_item_unique');
        });
    }
};
