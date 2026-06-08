<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->boolean('events_fetch_schedule_enabled')->default(false)->after('events_fetch_started_at');
            $table->string('events_fetch_schedule_at', 5)->nullable()->after('events_fetch_schedule_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropColumn([
                'events_fetch_schedule_enabled',
                'events_fetch_schedule_at',
            ]);
        });
    }
};
