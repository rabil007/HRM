<?php

use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        EmailTemplatesSeeder::seedLeaveRequestApprovedTemplate();
        EmailTemplatesSeeder::seedLeaveRequestRejectedTemplate();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        EmailTemplate::query()
            ->whereIn('slug', ['leave_request_approved', 'leave_request_rejected'])
            ->forceDelete();
    }
};
