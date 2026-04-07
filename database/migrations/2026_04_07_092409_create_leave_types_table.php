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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->decimal('days_per_year', 5, 2)->default(0);
            $table->enum('accrual_method', ['upfront', 'monthly', 'none'])->default('upfront');
            $table->boolean('carry_forward')->default(false);
            $table->unsignedInteger('max_carry_days')->default(0);
            $table->boolean('requires_approval')->default(true);
            $table->decimal('min_days', 4, 2)->default(0.5);
            $table->unsignedInteger('max_days')->nullable();
            $table->boolean('paid')->default(true);
            $table->boolean('uae_statutory')->default(false);
            $table->unsignedInteger('applicable_after')->default(0);
            $table->enum('gender_specific', ['all', 'male', 'female'])->default('all');
            $table->string('color', 20)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'uq_leave_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
