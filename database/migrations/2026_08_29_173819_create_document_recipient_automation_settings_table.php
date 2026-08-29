<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_recipient_automation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_rr_auto_settings_company_fk')
                ->cascadeOnDelete();
            $table->boolean('reminders_enabled')->default(false);
            $table->json('reminder_days_before_expiry')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_rr_auto_settings_created_by_fk')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_rr_auto_settings_updated_by_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique('company_id', 'doc_rr_auto_settings_company_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_recipient_automation_settings');
    }
};
