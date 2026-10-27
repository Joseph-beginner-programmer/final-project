<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('close_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('quantity_returned', 12, 2)->unsigned()->default(0)->after('quantity_received');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('returned_amount', 18, 2)->default(0)->after('amount_paid');
            $table->decimal('refunded_amount', 18, 2)->default(0)->after('returned_amount');
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_receipt_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->text('reason');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('debit_note_amount', 18, 2)->default(0);
            $table->decimal('refund_amount', 18, 2)->default(0);
            $table->foreignId('refund_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['purchase_order_receipt_batch_id', 'status']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('unit_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('invoice_unit_price', 14, 2)->nullable();
            $table->decimal('invoice_amount', 18, 2)->nullable();
            $table->unique(['purchase_return_id', 'purchase_order_receipt_id'], 'purchase_return_items_return_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['returned_amount', 'refunded_amount']);
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('quantity_returned');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
