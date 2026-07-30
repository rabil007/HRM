<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crew_timesheet_segments')) {
            return;
        }

        Schema::create('crew_timesheet_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('crew_timesheet_id')
                ->constrained('crew_timesheets', indexName: 'cts_timesheet_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('pay_category', 32);
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 8, 2);
            $table->string('source', 32);
            $table->foreignId('crew_assignment_id')
                ->nullable()
                ->constrained('crew_assignments', indexName: 'cts_assignment_fk')
                ->restrictOnDelete();
            $table->foreignId('crew_assignment_phase_id')
                ->nullable()
                ->constrained('crew_assignment_phases', indexName: 'cts_phase_fk')
                ->restrictOnDelete();
            $table->foreignId('crew_timesheet_preparation_line_id')
                ->nullable()
                ->constrained('crew_timesheet_preparation_lines', indexName: 'cts_prep_line_fk')
                ->nullOnDelete();
            $table->foreignId('vessel_id')
                ->nullable()
                ->constrained('vessels', indexName: 'cts_vessel_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id')
                ->nullable()
                ->constrained('clients', indexName: 'cts_client_fk')
                ->restrictOnDelete();
            $table->foreignId('rank_id')
                ->nullable()
                ->constrained('ranks', indexName: 'cts_rank_fk')
                ->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'crew_timesheet_id'], 'cts_company_timesheet_idx');
            $table->index(['crew_timesheet_id', 'sequence'], 'cts_timesheet_sequence_idx');
            $table->index(['crew_timesheet_id', 'pay_category'], 'cts_timesheet_category_idx');
            $table->index(['company_id', 'source'], 'cts_company_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_timesheet_segments');
    }
};
