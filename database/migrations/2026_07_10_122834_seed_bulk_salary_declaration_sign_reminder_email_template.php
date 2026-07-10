<?php

use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmailTemplatesSeeder::seedBulkSalaryDeclarationSignReminderTemplate();
    }

    public function down(): void
    {
        //
    }
};
