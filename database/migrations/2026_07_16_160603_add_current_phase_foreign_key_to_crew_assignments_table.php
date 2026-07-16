<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->foreign('current_phase_id')
                ->references('id')
                ->on('crew_assignment_phases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropForeign(['current_phase_id']);
        });
    }
};
