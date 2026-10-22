<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('internal_number')->unique();
            $table->string('invoice_number');
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_receipt_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->decimal('goods_amount', 18, 2)->default(0);
            $table->decimal('capitalized_charges', 18, 2)->default(0);
            $table->decimal('other_charges', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('receipt_value', 18, 2)->default(0);
            $table->decimal('price_variance', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['purchase_order_receipt_batch_id', 'status']);
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('unit_price', 14, 2)->unsigned();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->decimal('received_value', 18, 2)->unsigned();
            $table->unique(['purchase_invoice_id', 'purchase_order_receipt_id'], 'purchase_invoice_items_invoice_receipt_unique');
        });

        Schema::create('purchase_invoice_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2)->unsigned();
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('method');
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->string('status')->default('posted')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_invoice_charges');
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
