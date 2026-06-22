<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('contract_salary_components')
            ->where('component_code', 'OT_RATE')
            ->whereNull('deleted_at')
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible data normalization.
    }
};
