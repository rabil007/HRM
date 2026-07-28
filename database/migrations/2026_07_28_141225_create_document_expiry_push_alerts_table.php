<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_expiry_push_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('expiry_date_at_alert_time');
            $table->string('status', 20);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_category')->nullable();
            $table->timestamps();

            $table->unique(
                ['employee_document_id', 'user_id', 'expiry_date_at_alert_time'],
                'document_expiry_push_alerts_document_user_expiry_unique',
            );
            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiry_push_alerts');
    }
};
