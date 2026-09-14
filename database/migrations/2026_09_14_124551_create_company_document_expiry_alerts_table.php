<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_document_expiry_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->date('expiry_date_at_alert_time');
            $table->timestamp('alerted_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['company_document_id', 'expiry_date_at_alert_time'],
                'company_doc_expiry_alerts_doc_expiry_unique',
            );
            $table->index(['company_id', 'alerted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_expiry_alerts');
    }
};
