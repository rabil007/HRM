<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hikvision_persons', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('email');
            $table->string('photo_remote_key')->nullable()->after('photo_path');
            $table->dropColumn('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('hikvision_persons', function (Blueprint $table) {
            $table->text('photo_url')->nullable()->after('email');
            $table->dropColumn(['photo_path', 'photo_remote_key']);
        });
    }
};
