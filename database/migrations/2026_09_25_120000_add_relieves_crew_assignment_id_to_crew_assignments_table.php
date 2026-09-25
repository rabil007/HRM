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
            $table->foreignId('relieves_crew_assignment_id')
                ->nullable()
                ->after('previous_assignment_id')
                ->constrained('crew_assignments')
                ->nullOnDelete();

            $table->index(['company_id', 'relieves_crew_assignment_id'], 'crew_assignments_company_relieves_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropForeign(['relieves_crew_assignment_id']);
            $table->dropIndex('crew_assignments_company_relieves_idx');
            $table->dropColumn('relieves_crew_assignment_id');
        });
    }
};
