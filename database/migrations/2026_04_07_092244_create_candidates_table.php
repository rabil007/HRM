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
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 200);
            $table->string('phone', 30)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->decimal('current_salary', 12, 2)->nullable();
            $table->decimal('expected_salary', 12, 2)->nullable();
            $table->integer('notice_period')->nullable();
            $table->string('cv_path', 500)->nullable();
            $table->text('cover_letter')->nullable();
            $table->enum('source', ['website', 'linkedin', 'referral', 'agency', 'walk_in', 'other'])->nullable();
            $table->enum('stage', ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'])->default('applied');
            $table->enum('status', ['active', 'withdrawn', 'rejected', 'hired'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
