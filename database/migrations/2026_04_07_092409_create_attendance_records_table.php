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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable();
            $table->date('date');
            $table->dateTime('clock_in')->nullable();
            $table->dateTime('clock_out')->nullable();
            $table->decimal('clock_in_lat', 10, 8)->nullable();
            $table->decimal('clock_in_lng', 11, 8)->nullable();
            $table->decimal('clock_out_lat', 10, 8)->nullable();
            $table->decimal('clock_out_lng', 11, 8)->nullable();
            $table->decimal('hours_worked', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->enum('source', ['manual', 'biometric', 'mobile', 'web'])->default('web');
            $table->text('notes')->nullable();
            $table->enum('status', ['present', 'absent', 'late', 'half_day', 'holiday', 'weekend'])->default('present');
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'date'], 'uq_att_emp_date');
            $table->index('date', 'idx_att_date');
            $table->index('company_id', 'idx_att_company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
