<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unresolved uniqueness: only one pending/processing/needs_attention schedule
     * per assignment. Generated column is NULL for terminal statuses so history
     * rows do not occupy the unique slot (MySQL / MariaDB / SQLite).
     */
    public const UNRESOLVED_COLUMN = 'unresolved_assignment_id';

    public const UNRESOLVED_INDEX = 'uq_crew_scheduled_movements_unresolved_assignment';

    public const UNRESOLVED_EXPRESSION = "CASE WHEN status IN ('scheduled', 'processing', 'needs_attention') THEN crew_assignment_id ELSE NULL END";

    public function up(): void
    {
        Schema::create('crew_scheduled_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('crew_assignment_id')->constrained('crew_assignments')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('movement_action', 64);
            $table->json('action_payload');
            // DATETIME (not TIMESTAMP): store UTC instants without MySQL session TZ conversion.
            $table->dateTime('scheduled_at')->comment('UTC instant of scheduled intent');
            $table->string('scheduled_timezone', 64)->comment('IANA TZ used for operator local input/display');
            $table->string('status', 32);
            $table->foreignId('expected_current_phase_id')
                ->nullable()
                ->constrained('crew_assignment_phases')
                ->nullOnDelete();
            $table->string('expected_current_phase_code', 16)->nullable();
            $table->unsignedInteger('expected_current_phase_sequence')->nullable();
            $table->unsignedInteger('expected_vessel_id')->nullable();
            $table->string('expected_result_phase_code', 16)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('executed_at')->nullable()->comment('UTC instant when automatic execution succeeded');
            $table->dateTime('effective_occurred_at')->nullable()->comment('UTC operational occurrence applied to the movement');
            $table->dateTime('cancelled_at')->nullable()->comment('UTC instant when cancelled');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('execution_attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->dateTime('processing_started_at')->nullable()->comment('UTC claim time for processing');
            $table->timestamps();

            $table->unsignedBigInteger(self::UNRESOLVED_COLUMN)
                ->nullable()
                ->storedAs(self::UNRESOLVED_EXPRESSION);

            $table->unique(self::UNRESOLVED_COLUMN, self::UNRESOLVED_INDEX);
            $table->index(['company_id', 'status', 'scheduled_at']);
            $table->index(['crew_assignment_id', 'status']);
            $table->index(['company_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
            $table->index('employee_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_scheduled_movements');
    }
};
