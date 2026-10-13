<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('salesperson_id')->after('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('payment_terms')->default('cash')->after('expected_delivery_date');
            $table->decimal('discount_amount', 14, 2)->unsigned()->default(0)->after('payment_terms');
        });

        Schema::create('sales_order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2)->unsigned();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_charges');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salesperson_id');
            $table->dropColumn(['payment_terms', 'discount_amount']);
        });
    }
};
