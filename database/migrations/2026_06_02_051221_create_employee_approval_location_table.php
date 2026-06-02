<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_approval_location', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('approval_location_id')->constrained('approval_locations')->cascadeOnDelete();

            $table->primary(['employee_id', 'approval_location_id'], 'pk_employee_approval_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_approval_location');
    }
};
