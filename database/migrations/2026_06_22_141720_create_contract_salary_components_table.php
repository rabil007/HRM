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
        Schema::create('contract_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('employee_contracts')->cascadeOnDelete();
            $table->string('component_code', 50);
            $table->string('component_name', 200);
            $table->enum('rate_type', ['monthly', 'daily', 'hourly', 'fixed']);
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_id', 'contract_id', 'component_code'],
                'uq_contract_salary_component_code',
            );
            $table->index(['company_id', 'contract_id'], 'idx_contract_salary_components_contract');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_salary_components');
    }
};
