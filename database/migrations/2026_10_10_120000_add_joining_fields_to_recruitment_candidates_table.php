<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->date('expected_joining_date')->nullable()->after('cv_file_checksum');
            $table->date('actual_joining_date')->nullable()->after('expected_joining_date');
            $table->dateTime('joined_at')->nullable()->after('actual_joining_date');
            $table->foreignId('joined_by')
                ->nullable()
                ->after('joined_at')
                ->constrained('users', 'id', 'fk_rec_cand_joined_by')
                ->nullOnDelete();
            $table->string('joining_readiness_status', 30)->default('pending')->after('joined_by');
            $table->text('joining_readiness_notes')->nullable()->after('joining_readiness_status');
            $table->text('joining_blocker_notes')->nullable()->after('joining_readiness_notes');
            $table->foreignId('employee_id')
                ->nullable()
                ->after('joining_blocker_notes')
                ->constrained('employees', 'id', 'fk_rec_cand_employee')
                ->nullOnDelete();

            $table->index(['company_id', 'expected_joining_date'], 'idx_rec_cand_company_exp_join');
            $table->index(['company_id', 'actual_joining_date'], 'idx_rec_cand_company_act_join');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->dropIndex('idx_rec_cand_company_exp_join');
            $table->dropIndex('idx_rec_cand_company_act_join');
            $table->dropForeign('fk_rec_cand_joined_by');
            $table->dropForeign('fk_rec_cand_employee');
            $table->dropColumn([
                'expected_joining_date',
                'actual_joining_date',
                'joined_at',
                'joined_by',
                'joining_readiness_status',
                'joining_readiness_notes',
                'joining_blocker_notes',
                'employee_id',
            ]);
        });
    }
};
