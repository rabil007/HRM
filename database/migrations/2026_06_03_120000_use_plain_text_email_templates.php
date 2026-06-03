<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_templates')
            ->where('slug', 'document_share')
            ->update([
                'subject' => 'Documents from Overseas Marine Services',
                'body_html' => "Hello,\n\nPlease find the attached employee documents.\n\nThank you.",
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('slug', 'document_share')
            ->update([
                'subject' => 'Documents from {{organization_name}}',
                'body_html' => '<p>Hello {{recipient_name}},</p><p>{{sender_name}} has shared documents with you from {{organization_name}}.</p><p>{{message}}</p><p>Thank you.</p>',
                'updated_at' => now(),
            ]);
    }
};
