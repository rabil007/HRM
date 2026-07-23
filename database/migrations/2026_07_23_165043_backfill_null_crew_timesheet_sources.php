<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_timesheets') || ! Schema::hasColumn('crew_timesheets', 'source')) {
            return;
        }

        DB::table('crew_timesheets')
            ->whereNull('source')
            ->update(['source' => 'manual']);
    }

    public function down(): void
    {
        //
    }
};
