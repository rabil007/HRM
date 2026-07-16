<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_assignment_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('crew_assignment_id')->constrained('crew_assignments')->cascadeOnDelete();

            $table->string('phase_code', 16);
            $table->unsignedInteger('sequence');
            $table->string('status', 32);

            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();

            $table->json('details')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['crew_assignment_id', 'sequence']);
            $table->index(['company_id', 'phase_code']);
            $table->index(['crew_assignment_id', 'status']);
            $table->index(['crew_assignment_id', 'sequence']);
            $table->index(['actual_start_at']);
            $table->index(['actual_end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_assignment_phases');
    }
};
