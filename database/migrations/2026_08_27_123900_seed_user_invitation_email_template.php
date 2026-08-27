<?php

use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmailTemplatesSeeder::seedUserInvitationTemplate();
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->where('slug', 'user_invitation')
            ->forceDelete();
    }
};
