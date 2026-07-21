<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_timesheet_preparation_lines')) {
            Schema::create('crew_timesheet_preparation_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('crew_timesheet_preparation_id')
                    ->constrained('crew_timesheet_preparations', indexName: 'ctpl_preparation_fk')
                    ->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
                $table->foreignId('crew_assignment_id')->constrained('crew_assignments')->restrictOnDelete();
                $table->foreignId('crew_assignment_phase_id')
                    ->nullable()
                    ->constrained('crew_assignment_phases', indexName: 'ctpl_phase_fk')
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

                $table->index(
                    ['company_id', 'crew_timesheet_preparation_id'],
                    'ctpl_company_preparation_idx',
                );
                $table->index(
                    ['crew_timesheet_preparation_id', 'employee_id'],
                    'ctpl_preparation_employee_idx',
                );
                $table->index(
                    ['crew_assignment_id', 'crew_assignment_phase_id'],
                    'ctpl_assignment_phase_idx',
                );
                $table->index(['employee_id', 'pay_category'], 'ctpl_employee_pay_category_idx');
            });

            return;
        }

        $this->repairColumns();
        $this->repairIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_timesheet_preparation_lines');
    }

    private function repairColumns(): void
    {
        $columns = [
            'phase_code' => fn (Blueprint $table) => $table->string('phase_code', 8)->nullable(),
            'pay_category' => fn (Blueprint $table) => $table->string('pay_category', 32)->nullable(),
            'from_date' => fn (Blueprint $table) => $table->date('from_date')->nullable(),
            'to_date' => fn (Blueprint $table) => $table->date('to_date')->nullable(),
            'days' => fn (Blueprint $table) => $table->decimal('days', 8, 2)->nullable(),
            'source_actual_start_at' => fn (Blueprint $table) => $table->timestamp('source_actual_start_at')->nullable(),
            'source_actual_end_at' => fn (Blueprint $table) => $table->timestamp('source_actual_end_at')->nullable(),
            'warning_code' => fn (Blueprint $table) => $table->string('warning_code', 64)->nullable(),
            'remarks' => fn (Blueprint $table) => $table->text('remarks')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('crew_timesheet_preparation_lines', $column)) {
                Schema::table('crew_timesheet_preparation_lines', function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        }
    }

    private function repairIndexes(): void
    {
        $indexes = [
            'ctpl_company_preparation_idx' => ['company_id', 'crew_timesheet_preparation_id'],
            'ctpl_preparation_employee_idx' => ['crew_timesheet_preparation_id', 'employee_id'],
            'ctpl_assignment_phase_idx' => ['crew_assignment_id', 'crew_assignment_phase_id'],
            'ctpl_employee_pay_category_idx' => ['employee_id', 'pay_category'],
        ];

        foreach ($indexes as $name => $columns) {
            if (! Schema::hasIndex('crew_timesheet_preparation_lines', $name)) {
                Schema::table('crew_timesheet_preparation_lines', function (Blueprint $table) use ($columns, $name): void {
                    $table->index($columns, $name);
                });
            }
        }
    }
};
