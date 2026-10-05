<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipt_batches', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('received_by');
        });

        Schema::dropIfExists('purchase_order_receipt_attachments');
    }

    public function down(): void
    {
        Schema::create('purchase_order_receipt_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_receipt_batch_id')->constrained()->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->dateTime('created_at');
        });

        Schema::table('purchase_order_receipt_batches', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });
    }
};
