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
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'temporary'])->default('full_time');
            $table->string('location', 200)->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->unsignedInteger('vacancies')->default(1);
            $table->date('deadline')->nullable();
            $table->enum('status', ['draft', 'open', 'closed', 'on_hold'])->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
