<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'meta_name' => 'employee_announcement_notice',
                'meta_language' => 'en',
                'header_type' => 'none',
                'body_preview' => 'Hello, a company notice from {{1}} is available. Title: {{2}}. Summary: {{3}}. Priority: {{4}}. View link: {{5}}.',
                'enabled' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'meta_name' => 'announcement',
                'body_preview' => '{{1}} — {{2}}: {{3}}. Priority: {{4}}. Open: {{5}}',
                'updated_at' => now(),
            ]);
    }
};
