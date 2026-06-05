<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->string('events_fetch_status', 20)->default('idle')->after('events_last_fetched_at');
            $table->text('events_fetch_message')->nullable()->after('events_fetch_status');
            $table->timestamp('events_fetch_started_at')->nullable()->after('events_fetch_message');
        });
    }

    public function down(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropColumn([
                'events_fetch_status',
                'events_fetch_message',
                'events_fetch_started_at',
            ]);
        });
    }
};
