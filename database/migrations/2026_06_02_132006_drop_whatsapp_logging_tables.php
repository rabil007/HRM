<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
        Schema::dropIfExists('whatsapp_document_deliveries');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally empty — logging tables were removed from the application.
    }
};
