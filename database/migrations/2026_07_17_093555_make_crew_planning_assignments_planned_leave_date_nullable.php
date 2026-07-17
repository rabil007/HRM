<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->date('planned_leave_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('crew_planning_assignments')
            ->whereNull('planned_leave_date')
            ->update([
                'planned_leave_date' => DB::raw('planned_join_date'),
            ]);

        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->date('planned_leave_date')->nullable(false)->change();
        });
    }
};
