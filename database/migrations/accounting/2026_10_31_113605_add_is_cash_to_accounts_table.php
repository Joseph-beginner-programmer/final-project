<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('is_contra');
        });

        $usedAsCashAccount = DB::table('sales_payments')->pluck('cash_account_id')
            ->merge(DB::table('purchase_payments')->pluck('cash_account_id'))
            ->merge(DB::table('wage_payments')->pluck('cash_account_id'))
            ->filter()
            ->unique();

        if ($usedAsCashAccount->isNotEmpty()) {
            DB::table('accounts')->whereIn('id', $usedAsCashAccount)->update(['is_cash' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_cash');
        });
    }
};
