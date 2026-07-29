<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_leave_approval_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('default_hr_approver_employee_id')->nullable();
            $table->unsignedBigInteger('fallback_approver_employee_id')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('default_hr_approver_employee_id', 'company_leave_settings_hr_employee_fk')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();

            $table->foreign('fallback_approver_employee_id', 'company_leave_settings_fallback_employee_fk')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_leave_approval_settings');
    }
};
