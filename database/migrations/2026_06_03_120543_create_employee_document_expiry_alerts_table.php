<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_document_id')->constrained()->cascadeOnDelete();
            $table->date('expiry_date_at_alert_time');
            $table->timestamp('alerted_at');
            $table->timestamps();

            $table->unique(
                ['employee_document_id', 'expiry_date_at_alert_time'],
                'employee_document_expiry_alerts_document_expiry_unique',
            );
            $table->index(['company_id', 'alerted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_document_expiry_alerts');
    }
};
