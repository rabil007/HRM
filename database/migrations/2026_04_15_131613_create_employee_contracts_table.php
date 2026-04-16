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
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->enum('contract_type', ['limited', 'unlimited', 'part_time', 'contract'])->default('unlimited');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->string('labor_contract_id', 100)->nullable();
            $table->enum('status', ['active', 'ended', 'draft'])->default('active');
            $table->timestamps();

            $table->index(['company_id', 'employee_id'], 'idx_emp_contract_company_employee');
            $table->index(['employee_id', 'status'], 'idx_emp_contract_employee_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
