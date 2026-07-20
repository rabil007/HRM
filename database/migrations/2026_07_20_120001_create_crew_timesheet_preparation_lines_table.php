<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_timesheet_preparation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('crew_timesheet_preparation_id')
                ->constrained('crew_timesheet_preparations')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('crew_assignment_id')->constrained('crew_assignments')->restrictOnDelete();
            $table->foreignId('crew_assignment_phase_id')
                ->nullable()
                ->constrained('crew_assignment_phases')
                ->restrictOnDelete();
            $table->string('phase_code', 8);
            $table->string('pay_category', 32);
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 8, 2);
            $table->timestamp('source_actual_start_at')->nullable();
            $table->timestamp('source_actual_end_at')->nullable();
            $table->string('warning_code', 64)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'crew_timesheet_preparation_id']);
            $table->index(['crew_timesheet_preparation_id', 'employee_id']);
            $table->index(['crew_assignment_id', 'crew_assignment_phase_id']);
            $table->index(['employee_id', 'pay_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_timesheet_preparation_lines');
    }
};
