<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_charges', function (Blueprint $table) {
            $table->id();
            $table->string('chargeable_type');
            $table->unsignedBigInteger('chargeable_id');
            $table->string('description');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('include_in_cost')->default(true);

            $table->index(['chargeable_type', 'chargeable_id']);
        });

        Schema::dropIfExists('purchase_order_charges');
        Schema::dropIfExists('purchase_invoice_charges');
        Schema::dropIfExists('sales_order_charges');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_charges');

        Schema::create('purchase_order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2)->unsigned();
            $table->boolean('include_in_cost')->default(true);
        });

        Schema::create('purchase_invoice_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2)->unsigned();
        });

        Schema::create('sales_order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2)->unsigned();
        });
    }
};
