<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requirement_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_trans_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements', 'id', 'fk_req_trans_requirement')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('performed_by')->nullable()->constrained('users', 'id', 'fk_req_trans_performed_by')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(
                ['company_id', 'recruitment_requirement_id', 'created_at'],
                'idx_recruitment_req_trans_company_req_created',
            );
            $table->index(
                ['recruitment_requirement_id', 'to_status', 'created_at'],
                'idx_recruitment_req_trans_req_to_created',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requirement_status_transitions');
    }
};
