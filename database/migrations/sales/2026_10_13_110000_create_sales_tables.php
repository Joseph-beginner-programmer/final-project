<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('selling_price', 12, 2)->unsigned()->nullable()->after('reorder_point');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->unique();
            $table->string('customer_name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('so_number')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('status')->default('draft')->index();
            $table->decimal('total_amount', 14, 2)->unsigned()->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered', 12, 2)->unsigned();
            $table->decimal('quantity_delivered', 12, 2)->unsigned()->default(0);
            $table->decimal('quantity_returned', 12, 2)->unsigned()->default(0);
            $table->decimal('unit_price', 12, 2)->unsigned();
            $table->decimal('subtotal', 14, 2)->unsigned();
            $table->unique(['sales_order_id', 'product_id'], 'sales_order_items_order_product_unique');
        });

        Schema::create('sales_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number')->unique();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->unsigned();
            $table->decimal('cogs_total', 14, 2)->unsigned()->default(0);
            $table->unique(['sales_delivery_id', 'sales_order_item_id'], 'sales_delivery_items_delivery_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_delivery_items');
        Schema::dropIfExists('sales_deliveries');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('customers');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('selling_price');
        });
    }
};
