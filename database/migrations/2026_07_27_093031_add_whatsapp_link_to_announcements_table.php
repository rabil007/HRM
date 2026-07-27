<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('announcements', 'whatsapp_link')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            $table->string('whatsapp_link', 2048)
                ->nullable()
                ->after('channels');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('announcements', 'whatsapp_link')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('whatsapp_link');
        });
    }
};
