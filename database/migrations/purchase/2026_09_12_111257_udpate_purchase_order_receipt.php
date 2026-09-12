<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table) {
            $table->dropForeign(['received_by']);
            $table->dropColumn(['received_at', 'received_by']);

            $table->foreignId('purchase_order_receipt_batch_id')
                ->nullable()
                ->after('purchase_order_item_id')
                ->constrained('purchase_order_receipt_batches')
                ->restrictOnDelete();
        });
    }

     public function down(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_receipt_batch_id']);
            $table->dropColumn('purchase_order_receipt_batch_id');

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }
};