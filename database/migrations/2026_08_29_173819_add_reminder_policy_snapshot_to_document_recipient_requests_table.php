<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->json('reminder_policy_snapshot')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropColumn('reminder_policy_snapshot');
        });
    }
};
