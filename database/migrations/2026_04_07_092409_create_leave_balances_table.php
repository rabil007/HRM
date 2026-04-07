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
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id');
            $table->year('year');
            $table->decimal('entitled_days', 6, 2)->default(0);
            $table->decimal('used_days', 6, 2)->default(0);
            $table->decimal('pending_days', 6, 2)->default(0);
            $table->decimal('carried_days', 6, 2)->default(0);
            $table->decimal('remaining_days', 6, 2)->storedAs('(`entitled_days` + `carried_days` - `used_days` - `pending_days`)');
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'leave_type_id', 'year'], 'uq_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
