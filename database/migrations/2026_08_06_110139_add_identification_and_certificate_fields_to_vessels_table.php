<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $table->string('official_no')->nullable()->after('bhp');
            $table->string('call_sign')->nullable()->after('official_no');
            $table->string('imo_no')->nullable()->after('call_sign');
            $table->string('certificate_path')->nullable()->after('imo_no');
            $table->string('certificate_original_filename')->nullable()->after('certificate_path');
            $table->string('certificate_mime_type')->nullable()->after('certificate_original_filename');
            $table->unsignedInteger('certificate_size_bytes')->nullable()->after('certificate_mime_type');
            $table->string('certificate_checksum')->nullable()->after('certificate_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $table->dropColumn([
                'official_no',
                'call_sign',
                'imo_no',
                'certificate_path',
                'certificate_original_filename',
                'certificate_mime_type',
                'certificate_size_bytes',
                'certificate_checksum',
            ]);
        });
    }
};
