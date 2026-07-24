<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->exists();

        $bodyPreview = '{{1}} — {{2}}: {{3}}. Priority: {{4}}. Open: {{5}}';

        if ($exists) {
            DB::table('whatsapp_templates')
                ->where('slug', 'announcement')
                ->update([
                    'body_preview' => $bodyPreview,
                    'header_type' => 'none',
                    'enabled' => true,
                    'meta_name' => 'announcement',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('whatsapp_templates')->insert([
            'slug' => 'announcement',
            'label' => 'Announcement',
            'category' => 'general',
            'meta_name' => 'announcement',
            'meta_language' => 'en',
            'header_type' => 'none',
            'body_preview' => $bodyPreview,
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'body_preview' => '{{company}} — {{title}}: {{message}}. Priority: {{priority}}.',
                'updated_at' => now(),
            ]);
    }
};
