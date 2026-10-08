<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipt_batches', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->after('id');
        });

        // backfill existing batches before tightening the constraint
        DB::table('purchase_order_receipt_batches')->orderBy('id')->each(function ($batch) {
            DB::table('purchase_order_receipt_batches')
                ->where('id', $batch->id)
                ->update(['receipt_number' => sprintf('RCV-%s-%06d', Carbon::parse($batch->received_at)->format('Y'), $batch->id)]);
        });

        Schema::table('purchase_order_receipt_batches', function (Blueprint $table) {
            $table->string('receipt_number')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_receipt_batches', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
