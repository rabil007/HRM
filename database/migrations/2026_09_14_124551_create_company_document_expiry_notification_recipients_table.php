<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_document_expiry_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')
                ->constrained('company_document_expiry_notification_settings')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('to'); // 'to' or 'cc'
            $table->timestamps();

            $table->unique(['setting_id', 'user_id', 'type']);
            $table->index(['setting_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_expiry_notification_recipients');
    }
};
