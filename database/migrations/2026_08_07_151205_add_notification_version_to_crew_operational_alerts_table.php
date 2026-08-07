<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operational_alerts', function (Blueprint $table) {
            $table->unsignedInteger('notification_version')->default(1)->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('crew_operational_alerts', function (Blueprint $table) {
            $table->dropColumn('notification_version');
        });
    }
};
