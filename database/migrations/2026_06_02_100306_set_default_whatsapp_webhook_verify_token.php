<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run migrations.
     */
    public function up(): void
    {
        DB::table('whatsapp_settings')
            ->where('id', 1)
            ->where(function ($query): void {
                $query->whereNull('webhook_verify_token')
                    ->orWhere('webhook_verify_token', '');
            })
            ->update([
                'webhook_verify_token' => 'HERD_OMS_WHATSAPP_VERIFY_TOKEN',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('whatsapp_settings')
            ->where('id', 1)
            ->where('webhook_verify_token', 'HERD_OMS_WHATSAPP_VERIFY_TOKEN')
            ->update([
                'webhook_verify_token' => null,
                'updated_at' => now(),
            ]);
    }
};
