<?php

use App\Models\EmployeeProfileTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropColumn('contract_type');
        });

        if (Schema::hasTable('job_offers') && Schema::hasColumn('job_offers', 'contract_type')) {
            Schema::table('job_offers', function (Blueprint $table) {
                $table->dropColumn('contract_type');
            });
        }

        EmployeeProfileTemplate::query()->each(function (EmployeeProfileTemplate $template): void {
            $configuration = $template->configuration_json;

            if (! is_array($configuration)) {
                return;
            }

            unset($configuration['fields']['employee_contracts']['contract_type']);

            $template->update(['configuration_json' => $configuration]);
        });
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->enum('contract_type', ['limited', 'unlimited', 'part_time', 'contract'])
                ->default('unlimited')
                ->after('employee_id');
        });

        if (Schema::hasTable('job_offers')) {
            Schema::table('job_offers', function (Blueprint $table) {
                $table->enum('contract_type', ['limited', 'unlimited', 'part_time', 'contract'])
                    ->default('unlimited');
            });
        }
    }
};
