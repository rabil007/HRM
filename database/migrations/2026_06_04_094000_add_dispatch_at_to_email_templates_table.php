<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('dispatch_at', 5)->nullable()->after('cc_preset');
        });

        DB::table('email_templates')
            ->where('slug', 'document_expiry_alert')
            ->whereNull('dispatch_at')
            ->update(['dispatch_at' => '08:00']);
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('dispatch_at');
        });
    }
};
