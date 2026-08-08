<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\CrewRankPolicy')
            ->orWhere('subject_type', 'CrewRankPolicy')
            ->delete();

        Schema::dropIfExists('crew_rank_policies');

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_crew_assignments_company_tour_source');
            $table->dropColumn('tour_of_duty_source');
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->string('tour_of_duty_source', 40)->nullable()->after('tour_of_duty_days');
            $table->index(
                ['company_id', 'tour_of_duty_source'],
                'idx_crew_assignments_company_tour_source',
            );
        });

        Schema::create('crew_rank_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->cascadeOnDelete();
            $table->unsignedSmallInteger('tour_of_duty_days');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'rank_id'], 'uq_crew_rank_policies_company_rank');
            $table->index(['company_id', 'is_active'], 'idx_crew_rank_policies_company_active');
        });
    }
};
