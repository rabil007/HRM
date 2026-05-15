<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('language_name', 255);
            $table->boolean('is_spoken')->default(false);
            $table->boolean('is_written')->default(false);
            $table->boolean('is_understood')->default(false);
            $table->boolean('is_mother_tongue')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_languages');
    }
};
