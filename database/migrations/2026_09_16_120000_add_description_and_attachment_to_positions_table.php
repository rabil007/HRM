<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('attachment_path')->nullable()->after('status');
            $table->string('attachment_original_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime_type', 150)->nullable()->after('attachment_original_name');
            $table->unsignedBigInteger('attachment_size_bytes')->nullable()->after('attachment_mime_type');
            $table->string('attachment_checksum', 64)->nullable()->after('attachment_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'attachment_path',
                'attachment_original_name',
                'attachment_mime_type',
                'attachment_size_bytes',
                'attachment_checksum',
            ]);
        });
    }
};
