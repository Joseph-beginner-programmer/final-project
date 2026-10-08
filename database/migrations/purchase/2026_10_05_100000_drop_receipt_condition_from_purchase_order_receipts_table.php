<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table) {
            $table->dropColumn('receipt_condition');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table) {
            // nullable: the original values are gone once up() has run, so a rollback can't refill them
            $table->string('receipt_condition')->nullable()->after('quantity_received');
        });
    }
};
