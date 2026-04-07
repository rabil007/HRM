<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('employee_no', 50);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('nationality', 100)->nullable();
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable();
            $table->string('personal_email', 200)->nullable();
            $table->string('work_email', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('emergency_contact', 200)->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->text('address')->nullable();

            $table->date('hire_date');
            $table->date('probation_end_date')->nullable();
            $table->enum('contract_type', ['limited', 'unlimited', 'part_time', 'contract'])->default('unlimited');
            $table->date('contract_end_date')->nullable();

            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('housing_allowance', 12, 2)->default(0);
            $table->decimal('transport_allowance', 12, 2)->default(0);
            $table->decimal('other_allowances', 12, 2)->default(0);
            $table->string('bank_name', 200)->nullable();
            $table->string('bank_account_name', 200)->nullable();
            $table->string('iban', 50)->nullable();

            $table->string('visa_number', 100)->nullable();
            $table->date('visa_expiry')->nullable();
            $table->string('visa_type', 100)->nullable();
            $table->string('emirates_id', 30)->nullable();
            $table->date('emirates_id_expiry')->nullable();
            $table->string('passport_number', 50)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('work_permit_number', 100)->nullable();
            $table->date('work_permit_expiry')->nullable();
            $table->string('labor_card_number', 100)->nullable();
            $table->date('labor_card_expiry')->nullable();
            $table->string('mohre_uid', 100)->nullable();

            $table->enum('status', ['active', 'inactive', 'on_leave', 'terminated'])->default('active');
            $table->date('termination_date')->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_no'], 'uq_emp_no_company');
            $table->index('company_id', 'idx_emp_company');
            $table->index('department_id', 'idx_emp_department');
            $table->index('manager_id', 'idx_emp_manager');
            $table->index('visa_expiry', 'idx_emp_visa_exp');
            $table->index('emirates_id_expiry', 'idx_emp_eid_exp');
            $table->index('passport_expiry', 'idx_emp_passport');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
