<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('assignment_no', 64);
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignId('company_visa_type_id')->nullable()->constrained('company_visa_types')->nullOnDelete();

            $table->string('status', 32);
            $table->unsignedBigInteger('current_phase_id')->nullable();

            $table->timestamp('planned_join_at')->nullable();
            $table->timestamp('planned_signoff_at')->nullable();
            $table->timestamp('planned_travel_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('previous_assignment_id')->nullable()->constrained('crew_assignments')->nullOnDelete();
            $table->foreignId('employee_deployment_id')->nullable()->constrained('employee_deployments')->nullOnDelete();
            $table->foreignId('crew_planning_assignment_id')->nullable()->constrained('crew_planning_assignments')->nullOnDelete();

            $table->string('source', 32)->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'assignment_no']);
            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'vessel_id']);
            $table->index(['company_id', 'planned_join_at']);
            $table->index(['company_id', 'planned_signoff_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_assignments');
    }
};
