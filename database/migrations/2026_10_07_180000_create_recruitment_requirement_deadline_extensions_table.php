<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requirement_deadline_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_dl_ext_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements', 'id', 'fk_req_dl_ext_requirement')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users', 'id', 'fk_req_dl_ext_requested_by')->restrictOnDelete();
            $table->string('initiator', 20);
            $table->date('old_deadline');
            $table->date('requested_deadline');
            $table->text('reason')->nullable();
            $table->string('status', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'fk_req_dl_ext_decided_by')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(
                ['company_id', 'recruitment_requirement_id', 'status'],
                'idx_req_dl_ext_company_req_status',
            );
            $table->index(
                ['recruitment_requirement_id', 'created_at'],
                'idx_req_dl_ext_req_created',
            );
            $table->index(
                ['company_id', 'status', 'created_at'],
                'idx_req_dl_ext_company_status_created',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requirement_deadline_extensions');
    }
};
