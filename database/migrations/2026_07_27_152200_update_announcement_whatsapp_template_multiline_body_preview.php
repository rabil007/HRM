<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BODY_PREVIEW = "Hello,\nA company notice from {{1}} is available for you.\n\nTitle: {{2}}\nSummary: {{3}}\nPriority: {{4}}\nView link: {{5}}";

    public function up(): void
    {
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'body_preview' => self::BODY_PREVIEW,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'body_preview' => 'Hello, a company notice from {{1}} is available. Title: {{2}}. Summary: {{3}}. Priority: {{4}}. View link: {{5}}.',
                'updated_at' => now(),
            ]);
    }
};
