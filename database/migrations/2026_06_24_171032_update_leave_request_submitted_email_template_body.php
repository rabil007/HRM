<?php

use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();
    }

    public function down(): void
    {
        // Body copy is non-destructive to restore; re-seed on rollback if needed.
    }
};
