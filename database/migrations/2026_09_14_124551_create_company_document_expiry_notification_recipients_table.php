<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recover from a prior partial MySQL DDL run where the table was created
        // but foreign keys/indexes failed (identifier too long). The table may
        // exist without constraints while this migration remains unrecorded.
        Schema::dropIfExists('company_document_expiry_notification_recipients');

        Schema::create('company_document_expiry_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')
                ->constrained('company_document_expiry_notification_settings', 'id', 'cdnr_setting_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('to'); // 'to' or 'cc'
            $table->timestamps();

            $table->unique(['setting_id', 'user_id', 'type'], 'cdnr_setting_user_type_unique');
            $table->index(['setting_id', 'type'], 'cdnr_setting_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_expiry_notification_recipients');
    }
};
