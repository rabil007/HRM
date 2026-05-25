<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profile_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('configuration_json');
            $table->timestamps();

            $table->index(['company_id', 'is_active'], 'idx_ept_company_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profile_templates');
    }
};
