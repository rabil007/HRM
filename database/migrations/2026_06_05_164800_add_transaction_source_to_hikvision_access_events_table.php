<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->string('transaction_source')->nullable()->after('event_source');
        });
    }

    public function down(): void
    {
        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->dropColumn('transaction_source');
        });
    }
};
