<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'branches',
            'departments',
            'positions',
            'employee_documents',
            'employee_document_versions',
            'employee_contracts',
            'employee_bank_accounts',
            'employee_profile_templates',
            'employee_sea_services',
            'employee_trainings',
            'employee_vaccinations',
            'employee_work_experiences',
            'employee_languages',
            'employee_education_qualifications',
            'document_types',
            'clients',
            'ranks',
            'courses',
            'banks',
            'countries',
            'currencies',
            'genders',
            'religions',
            'visa_types',
            'company_visa_types',
            'vessel_types',
            'approval_locations',
            'sssa_options',
            'whatsapp_templates',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'deleted_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'deleted_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
