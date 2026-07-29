<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approval_policy_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_approval_policy_id')->constrained('leave_approval_policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('approver_type', 50);
            $table->foreignId('approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['leave_approval_policy_id', 'sequence'], 'leave_approval_policy_steps_policy_sequence_unique');
            $table->index(['company_id', 'leave_approval_policy_id'], 'leave_approval_steps_company_policy_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approval_policy_steps');
    }
};
