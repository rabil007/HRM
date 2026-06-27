<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 12, 2);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'period_id', 'employee_id'], 'idx_salary_inputs_period_employee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_inputs');
    }
};
