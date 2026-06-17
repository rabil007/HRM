<?php

use App\Models\OnboardingTemplate;
use App\Support\Vessels\BackfillVesselsFromLegacyNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_sea_services', 'vessel_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->foreignId('vessel_id')
                    ->nullable()
                    ->after('vessel_type_id')
                    ->constrained('vessels')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('employee_deployments', 'vessel_id')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->foreignId('vessel_id')
                    ->nullable()
                    ->after('company_visa_type_id')
                    ->constrained('vessels')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('employee_sea_services', 'vessel_name')) {
            app(BackfillVesselsFromLegacyNames::class)->execute();
        }

        if (Schema::hasColumn('employee_sea_services', 'vessel_name')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->dropColumn(['vessel_name', 'grt', 'bhp']);
            });
        }

        if (Schema::hasColumn('employee_deployments', 'vessel_name')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->dropColumn('vessel_name');
            });
        }

        $this->migrateOnboardingTemplates();
    }

    public function down(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->string('vessel_name', 255)->default('')->after('vessel_type_id');
            $table->decimal('grt', 12, 2)->nullable()->after('end_date');
            $table->unsignedInteger('bhp')->nullable()->after('grt');
        });

        Schema::table('employee_deployments', function (Blueprint $table) {
            $table->string('vessel_name', 255)->nullable()->after('company_visa_type_id');
        });

        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropForeign(['vessel_id']);
            $table->dropColumn('vessel_id');
        });

        Schema::table('employee_deployments', function (Blueprint $table) {
            $table->dropForeign(['vessel_id']);
            $table->dropColumn('vessel_id');
        });

        $this->revertOnboardingTemplates();
    }

    private function migrateOnboardingTemplates(): void
    {
        if (! Schema::hasTable('onboarding_templates')) {
            return;
        }

        OnboardingTemplate::query()->orderBy('id')->each(function (OnboardingTemplate $template): void {
            $tasks = $template->tasks;
            if (! is_array($tasks)) {
                return;
            }
            if (($tasks['version'] ?? null) !== 2 || ! isset($tasks['stages']) || ! is_array($tasks['stages'])) {
                return;
            }

            $changed = false;
            foreach ($tasks['stages'] as $si => $stage) {
                if (! isset($stage['sea_service_fields']) || ! is_array($stage['sea_service_fields'])) {
                    continue;
                }
                foreach ($stage['sea_service_fields'] as $fi => $fieldReq) {
                    if (! is_array($fieldReq)) {
                        continue;
                    }
                    $key = (string) ($fieldReq['key'] ?? '');
                    if ($key === 'vessel_name') {
                        $tasks['stages'][$si]['sea_service_fields'][$fi]['key'] = 'vessel_id';
                        $changed = true;
                    }
                    if (in_array($key, ['grt', 'bhp'], true)) {
                        unset($tasks['stages'][$si]['sea_service_fields'][$fi]);
                        $tasks['stages'][$si]['sea_service_fields'] = array_values($tasks['stages'][$si]['sea_service_fields']);
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $template->tasks = $tasks;
                $template->save();
            }
        });
    }

    private function revertOnboardingTemplates(): void
    {
        if (! Schema::hasTable('onboarding_templates')) {
            return;
        }

        OnboardingTemplate::query()->orderBy('id')->each(function (OnboardingTemplate $template): void {
            $tasks = $template->tasks;
            if (! is_array($tasks)) {
                return;
            }
            if (($tasks['version'] ?? null) !== 2 || ! isset($tasks['stages']) || ! is_array($tasks['stages'])) {
                return;
            }

            $changed = false;
            foreach ($tasks['stages'] as $si => $stage) {
                if (! isset($stage['sea_service_fields']) || ! is_array($stage['sea_service_fields'])) {
                    continue;
                }
                foreach ($stage['sea_service_fields'] as $fi => $fieldReq) {
                    if (! is_array($fieldReq)) {
                        continue;
                    }
                    if (($fieldReq['key'] ?? '') === 'vessel_id') {
                        $tasks['stages'][$si]['sea_service_fields'][$fi]['key'] = 'vessel_name';
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $template->tasks = $tasks;
                $template->save();
            }
        });
    }
};
