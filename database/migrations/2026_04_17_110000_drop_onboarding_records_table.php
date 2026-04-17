<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('onboarding_records');
    }

    public function down(): void
    {
        Schema::create('onboarding_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('onboarding_templates')->nullOnDelete();
            $table->json('task_progress')->nullable();
            $table->date('start_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->string('stage', 50)->default('draft');
            $table->timestamps();
        });
    }
};
