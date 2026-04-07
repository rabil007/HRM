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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->string('logo', 500)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('company_size', 50)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->string('country', 100)->default('UAE');
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('website', 300)->nullable();
            $table->string('currency', 10)->default('AED');
            $table->string('timezone', 100)->default('Asia/Dubai');
            $table->string('fiscal_year_start', 5)->default('01-01');
            $table->enum('payroll_cycle', ['monthly', 'biweekly', 'weekly'])->default('monthly');
            $table->json('working_days');
            $table->string('wps_agent_code', 100)->nullable();
            $table->string('wps_mol_uid', 100)->nullable();
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
