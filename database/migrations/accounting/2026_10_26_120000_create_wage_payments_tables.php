<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->date('payment_date');
            $table->date('period_end');
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->string('status')->default('posted')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wage_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wage_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('hours', 10, 2)->unsigned();
            $table->decimal('amount', 18, 2)->unsigned();
            $table->unique(['wage_payment_id', 'employee_id']);
        });

        Schema::table('production_result_labors', function (Blueprint $table) {
            $table->foreignId('wage_payment_id')->nullable()->after('total_cost')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_result_labors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wage_payment_id');
        });
        Schema::dropIfExists('wage_payment_lines');
        Schema::dropIfExists('wage_payments');
    }
};
