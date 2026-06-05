<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->timestamp('mq_subscribed_at')->nullable()->after('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropColumn('mq_subscribed_at');
        });
    }
};
