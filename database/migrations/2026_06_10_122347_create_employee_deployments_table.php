<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('company_visa_type_id')->nullable()->constrained('company_visa_types')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('vessel_name', 255)->nullable();
            $table->date('hire_date')->nullable();
            $table->date('arrived_date')->nullable();
            $table->date('standby_from')->nullable();
            $table->date('standby_to')->nullable();
            $table->date('joined_date')->nullable();
            $table->date('disembarked_date')->nullable();
            $table->date('travelled_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'joined_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_deployments');
    }
};
