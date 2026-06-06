<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hikvision_settings', 'mq_subscribed_at')) {
            return;
        }

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropColumn('mq_subscribed_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('hikvision_settings', 'mq_subscribed_at')) {
            return;
        }

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->timestamp('mq_subscribed_at')->nullable()->after('enabled');
        });
    }
};
