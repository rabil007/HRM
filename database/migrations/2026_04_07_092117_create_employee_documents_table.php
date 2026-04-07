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
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('type', [
                'visa',
                'emirates_id',
                'passport',
                'work_permit',
                'labor_card',
                'contract',
                'certificate',
                'other',
            ]);
            $table->string('title', 200)->nullable();
            $table->string('file_path', 500);
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['valid', 'expired', 'expiring_soon'])->default('valid');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('expiry_date', 'idx_doc_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
