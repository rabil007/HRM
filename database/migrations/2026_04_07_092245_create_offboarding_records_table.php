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
        Schema::create('offboarding_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->date('last_working_day');
            $table->enum('reason', ['resignation', 'termination', 'contract_end', 'retirement', 'other']);
            $table->text('reason_details')->nullable();
            $table->json('clearance_checklist')->nullable();
            $table->decimal('gratuity_amount', 12, 2)->nullable();
            $table->text('exit_interview_notes')->nullable();
            $table->enum('status', ['initiated', 'in_progress', 'completed'])->default('initiated');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offboarding_records');
    }
};
