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
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('interviewer_id')->constrained('users');
            $table->unsignedInteger('round')->default(1);
            $table->enum('type', ['phone', 'video', 'in_person', 'technical', 'panel'])->default('in_person');
            $table->dateTime('scheduled_at');
            $table->unsignedInteger('duration_mins')->default(60);
            $table->string('location', 300)->nullable();
            $table->string('meeting_link', 500)->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->enum('outcome', ['pending', 'passed', 'failed', 'no_show', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
