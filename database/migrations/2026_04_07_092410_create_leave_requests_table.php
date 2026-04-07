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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 5, 2);
            $table->text('reason')->nullable();
            $table->json('attachments')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date'], 'idx_lr_dates');
            $table->index('company_id', 'idx_lr_company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
