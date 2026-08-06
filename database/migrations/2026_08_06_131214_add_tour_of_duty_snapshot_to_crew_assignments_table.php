<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('tour_of_duty_days')->nullable()->after('planned_signoff_at');
            $table->string('tour_of_duty_source', 40)->nullable()->after('tour_of_duty_days');
            $table->string('planned_signoff_source', 40)->nullable()->after('tour_of_duty_source');
            $table->text('planned_signoff_override_reason')->nullable()->after('planned_signoff_source');

            $table->index(
                ['company_id', 'tour_of_duty_source'],
                'idx_crew_assignments_company_tour_source',
            );
            $table->index(
                ['company_id', 'planned_signoff_source'],
                'idx_crew_assignments_company_signoff_source',
            );
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_crew_assignments_company_tour_source');
            $table->dropIndex('idx_crew_assignments_company_signoff_source');
            $table->dropColumn([
                'tour_of_duty_days',
                'tour_of_duty_source',
                'planned_signoff_source',
                'planned_signoff_override_reason',
            ]);
        });
    }
};
