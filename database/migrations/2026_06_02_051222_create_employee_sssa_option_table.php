<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sssa_option', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('sssa_option_id')->constrained('sssa_options')->cascadeOnDelete();

            $table->primary(['employee_id', 'sssa_option_id'], 'pk_employee_sssa_option');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sssa_option');
    }
};
