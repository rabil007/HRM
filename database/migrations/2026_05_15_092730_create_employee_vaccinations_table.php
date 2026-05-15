<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_vaccinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('vaccination_name', 255);
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->date('first_dose_date')->nullable();
            $table->date('second_dose_date')->nullable();
            $table->date('booster_dose_date')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_vaccinations');
    }
};
