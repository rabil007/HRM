<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('employee_contracts')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contract_id', 'version'], 'uq_contract_salary_revision_version');
            $table->index(['company_id', 'contract_id', 'effective_from'], 'idx_contract_salary_revisions_effective');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_salary_revisions');
    }
};
