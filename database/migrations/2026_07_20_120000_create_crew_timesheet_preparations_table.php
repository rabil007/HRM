<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_timesheet_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32);
            $table->date('cutoff_date')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'payroll_period_id', 'version'],
                'ctp_company_period_version_uq',
            );
            $table->index(['company_id', 'status'], 'ctp_company_status_idx');
            $table->index(['payroll_period_id', 'status'], 'ctp_period_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_timesheet_preparations');
    }
};
