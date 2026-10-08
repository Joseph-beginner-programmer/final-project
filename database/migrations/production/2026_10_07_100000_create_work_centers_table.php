<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // reference data the schema itself depends on (formulas get a required FK to these in the next
        // migration), so it ships with the migration rather than an optional seeder
        DB::table('work_centers')->insert([
            ['code' => 'MOLDING', 'name' => 'Molding', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'ASSEMBLY', 'name' => 'Assembly Line', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_centers');
    }
};
