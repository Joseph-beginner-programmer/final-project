<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->decimal('total_cost', 12, 2)->default(0)->after('quantity_planned');
            $table->decimal('quantity_consumed', 12, 2)->default(0)->after('total_cost');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'quantity_consumed']);
        });
    }
};
