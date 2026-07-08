<?php

use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmailTemplatesSeeder::seedBulkSalaryDeclarationTemplate();
        EmailTemplatesSeeder::seedBulkSalaryCertificateTemplate();
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->whereIn('slug', ['bulk_salary_declaration', 'bulk_salary_certificate'])
            ->forceDelete();
    }
};
