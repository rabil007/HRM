<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_movement_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('crew_assignment_id')->constrained('crew_assignments')->restrictOnDelete();
            $table->foreignId('crew_assignment_phase_id')
                ->nullable()
                ->constrained('crew_assignment_phases')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->json('original_values');
            $table->json('proposed_values');
            $table->json('applied_values')->nullable();
            $table->text('reason');
            $table->text('decision_notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['crew_assignment_id', 'status']);
            $table->index(['crew_assignment_phase_id', 'status']);
            $table->index('requested_by');
            $table->index('decided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_movement_corrections');
    }
};
