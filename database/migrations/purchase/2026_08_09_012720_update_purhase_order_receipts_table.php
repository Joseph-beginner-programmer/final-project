<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table) {
            $table->dropColumn('notes');
            $table->string('receipt_condition')->after('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table) {
            $table->dropColumn('receipt_condition');
            $table->text('notes')->nullable();
        });
    }
};
