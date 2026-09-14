<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->where('slug', 'company_document_expiry_alert')->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'slug' => 'company_document_expiry_alert',
            'label' => 'Company document expiry alert',
            'category' => 'notification',
            'to_preset' => null,
            'cc_preset' => null,
            'subject' => 'Company Document Expiry Alert',
            'body_html' => 'Automated company document expiry summary email.',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('slug', 'company_document_expiry_alert')->delete();
    }
};
