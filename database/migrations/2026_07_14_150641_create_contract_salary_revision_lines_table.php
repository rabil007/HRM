<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_salary_revision_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('contract_salary_revisions')->cascadeOnDelete();
            $table->string('component_code', 50);
            $table->string('component_name', 200);
            $table->enum('rate_type', ['monthly', 'daily', 'hourly', 'fixed']);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['revision_id', 'component_code'], 'uq_contract_salary_revision_line_code');
            $table->index(['company_id', 'revision_id'], 'idx_contract_salary_revision_lines_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_salary_revision_lines');
    }
};
