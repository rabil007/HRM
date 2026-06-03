<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->where('slug', 'document_expiry_alert')->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'slug' => 'document_expiry_alert',
            'label' => 'Document expiry alert',
            'category' => 'notification',
            'to_preset' => null,
            'cc_preset' => null,
            'subject' => 'Document expiry alert — action required within 30 days',
            'body_html' => "Hello,\n\nThe following employee documents will expire within the next 30 days. Please review and renew them before the expiry date.\n\nThank you.",
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('slug', 'document_expiry_alert')->delete();
    }
};
