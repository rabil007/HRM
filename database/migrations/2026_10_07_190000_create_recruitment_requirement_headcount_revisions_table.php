<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requirement_headcount_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_hc_rev_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements', 'id', 'fk_req_hc_rev_requirement')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users', 'id', 'fk_req_hc_rev_requested_by')->restrictOnDelete();
            $table->string('initiator', 20);
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'fk_req_hc_rev_decided_by')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(
                ['company_id', 'recruitment_requirement_id', 'status'],
                'idx_req_hc_rev_company_req_status',
            );
            $table->index(
                ['recruitment_requirement_id', 'created_at'],
                'idx_req_hc_rev_req_created',
            );
        });

        Schema::create('recruitment_requirement_headcount_revision_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_hc_rev_line_company')->cascadeOnDelete();
            $table->foreignId('headcount_revision_id')->constrained('recruitment_requirement_headcount_revisions', 'id', 'fk_req_hc_rev_line_revision')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_line_id')->nullable()->constrained('recruitment_requirement_lines', 'id', 'fk_req_hc_rev_line_req_line')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions', 'id', 'fk_req_hc_rev_line_position')->nullOnDelete();
            $table->string('position_title');
            $table->unsignedInteger('old_headcount');
            $table->unsignedInteger('requested_headcount');
            $table->timestamps();

            $table->index(
                ['company_id', 'headcount_revision_id'],
                'idx_req_hc_rev_line_company_revision',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requirement_headcount_revision_lines');
        Schema::dropIfExists('recruitment_requirement_headcount_revisions');
    }
};
