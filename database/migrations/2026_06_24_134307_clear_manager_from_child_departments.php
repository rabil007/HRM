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
        DB::table('departments')
            ->whereNotNull('parent_id')
            ->whereNotNull('manager_id')
            ->update(['manager_id' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Historical manager assignments on child departments cannot be restored.
    }
};
