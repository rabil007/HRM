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
        Schema::create('crew_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->date('standby_from')->nullable();
            $table->date('standby_to')->nullable();
            $table->decimal('standby_days', 8, 2)->nullable();
            $table->date('onsite_from')->nullable();
            $table->date('onsite_to')->nullable();
            $table->decimal('onsite_days', 8, 2)->nullable();
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('additional_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'period_id']);
            $table->index('period_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crew_timesheets');
    }
};
