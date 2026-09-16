<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->timestamp('planned_arrival_at')->nullable()->after('planned_join_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropColumn('planned_arrival_at');
        });
    }
};
