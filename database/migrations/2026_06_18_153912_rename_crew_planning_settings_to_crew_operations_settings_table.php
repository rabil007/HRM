<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('crew_planning_settings', 'crew_operations_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('crew_operations_settings', 'crew_planning_settings');
    }
};
