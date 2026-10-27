<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->decimal('returned_amount', 18, 2)->default(0)->after('amount_paid');
            $table->decimal('refunded_amount', 18, 2)->default(0)->after('returned_amount');
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->text('reason');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('cost_restocked', 18, 2)->default(0);
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('credit_note_amount', 18, 2)->default(0);
            $table->decimal('refund_amount', 18, 2)->default(0);
            $table->foreignId('refund_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['sales_delivery_id', 'status']);
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_delivery_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->string('condition')->default('good');
            $table->decimal('unit_price', 12, 2)->unsigned()->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('cost_total', 18, 2)->default(0);
            $table->unique(['sales_return_id', 'sales_delivery_item_id'], 'sales_return_items_return_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['returned_amount', 'refunded_amount']);
        });
    }
};
