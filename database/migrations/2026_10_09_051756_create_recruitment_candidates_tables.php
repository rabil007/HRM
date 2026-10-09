<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_rec_cand_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')
                ->nullable()
                ->constrained('recruitment_requirements', 'id', 'fk_rec_cand_requirement')
                ->nullOnDelete();
            $table->foreignId('recruitment_requirement_line_id')
                ->nullable()
                ->constrained('recruitment_requirement_lines', 'id', 'fk_rec_cand_line')
                ->nullOnDelete();
            $table->string('requirement_number_snapshot', 30);
            $table->string('position_title_snapshot', 255);
            $table->string('name', 200);
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email_normalized', 200)->nullable();
            $table->string('phone_normalized', 50)->nullable();
            $table->foreignId('nationality_id')
                ->nullable()
                ->constrained('countries', 'id', 'fk_rec_cand_nationality')
                ->nullOnDelete();
            $table->string('source', 30)->nullable();
            $table->text('notes')->nullable();
            $table->string('stage', 30)->default('applied');
            $table->string('interview_outcome', 30)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('pre_rejection_stage', 30)->nullable();
            $table->dateTime('interview_scheduled_at')->nullable();
            $table->foreignId('interviewer_user_id')
                ->nullable()
                ->constrained('users', 'id', 'fk_rec_cand_interviewer')
                ->nullOnDelete();
            $table->string('external_interviewer_name', 200)->nullable();
            $table->string('interview_mode', 20)->nullable();
            $table->string('interview_location', 300)->nullable();
            $table->text('interview_feedback')->nullable();
            $table->string('cv_path', 500)->nullable();
            $table->string('cv_original_file_name', 255)->nullable();
            $table->string('cv_mime_type', 100)->nullable();
            $table->unsignedBigInteger('cv_file_size_bytes')->nullable();
            $table->string('cv_file_checksum', 64)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', 'id', 'fk_rec_cand_created_by')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users', 'id', 'fk_rec_cand_updated_by')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'stage'], 'idx_rec_cand_company_stage');
            $table->index(['company_id', 'interview_outcome'], 'idx_rec_cand_company_outcome');
            $table->index(['company_id', 'recruitment_requirement_id'], 'idx_rec_cand_company_requirement');
            $table->index(['company_id', 'recruitment_requirement_line_id'], 'idx_rec_cand_company_line');
            $table->index(['company_id', 'email_normalized'], 'idx_rec_cand_company_email_norm');
            $table->index(['company_id', 'phone_normalized'], 'idx_rec_cand_company_phone_norm');
            $table->index(['company_id', 'name'], 'idx_rec_cand_company_name');
        });

        Schema::create('recruitment_candidate_stage_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_rec_cand_trans_company')->cascadeOnDelete();
            $table->foreignId('recruitment_candidate_id')
                ->constrained('recruitment_candidates', 'id', 'fk_rec_cand_trans_candidate')
                ->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->string('from_outcome', 30)->nullable();
            $table->string('to_outcome', 30)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users', 'id', 'fk_rec_cand_trans_performed_by')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['company_id', 'recruitment_candidate_id', 'created_at'],
                'idx_rec_cand_trans_company_cand_created',
            );
            $table->index(
                ['recruitment_candidate_id', 'action', 'created_at'],
                'idx_rec_cand_trans_cand_action_created',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidate_stage_transitions');
        Schema::dropIfExists('recruitment_candidates');
    }
};
