<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('work_orders')->where('status', 'completed_short')->exists()) {
            throw new RuntimeException('Some work orders are completed_short; convert them before removing the status.');
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
        });
    }
};
