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
        Schema::create('employee_sea_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('vessel_name', 255);
            $table->string('rank_label', 255)->nullable();
            $table->unsignedInteger('total_months')->default(0);
            $table->unsignedSmallInteger('total_days')->default(0);
            $table->decimal('grt', 12, 2)->nullable();
            $table->unsignedInteger('bhp')->nullable();
            $table->string('client', 255)->nullable();
            $table->boolean('is_offshore')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_sea_services');
    }
};
