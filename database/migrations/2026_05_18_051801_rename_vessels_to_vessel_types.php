<?php

use App\Models\OnboardingTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_sea_services') && Schema::hasColumn('employee_sea_services', 'vessel_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->dropForeign(['vessel_id']);
            });
        }

        if (Schema::hasTable('vessels')) {
            Schema::rename('vessels', 'vessel_types');
        }

        if (Schema::hasTable('employee_sea_services') && Schema::hasColumn('employee_sea_services', 'vessel_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->renameColumn('vessel_id', 'vessel_type_id');
            });
        }

        if (Schema::hasTable('employee_sea_services') && Schema::hasColumn('employee_sea_services', 'vessel_type_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->foreign('vessel_type_id')->references('id')->on('vessel_types')->restrictOnDelete();
            });
        }

        $permissionRenames = [
            'settings.master-data.vessels.view' => 'settings.master-data.vessel-types.view',
            'settings.master-data.vessels.create' => 'settings.master-data.vessel-types.create',
            'settings.master-data.vessels.update' => 'settings.master-data.vessel-types.update',
            'settings.master-data.vessels.delete' => 'settings.master-data.vessel-types.delete',
        ];

        foreach ($permissionRenames as $from => $to) {
            DB::table('permissions')
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->update(['name' => $to]);
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
                        $tasks['stages'][$si]['sea_service_fields'][$fi]['key'] = 'vessel_type_id';
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

    public function down(): void
    {
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
                    if (($fieldReq['key'] ?? '') === 'vessel_type_id') {
                        $tasks['stages'][$si]['sea_service_fields'][$fi]['key'] = 'vessel_id';
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $template->tasks = $tasks;
                $template->save();
            }
        });

        $permissionRenames = [
            'settings.master-data.vessel-types.view' => 'settings.master-data.vessels.view',
            'settings.master-data.vessel-types.create' => 'settings.master-data.vessels.create',
            'settings.master-data.vessel-types.update' => 'settings.master-data.vessels.update',
            'settings.master-data.vessel-types.delete' => 'settings.master-data.vessels.delete',
        ];

        foreach ($permissionRenames as $from => $to) {
            DB::table('permissions')
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->update(['name' => $to]);
        }

        if (Schema::hasTable('employee_sea_services') && Schema::hasColumn('employee_sea_services', 'vessel_type_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->dropForeign(['vessel_type_id']);
            });
        }

        if (Schema::hasTable('employee_sea_services') && Schema::hasColumn('employee_sea_services', 'vessel_type_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->renameColumn('vessel_type_id', 'vessel_id');
            });
        }

        if (Schema::hasTable('vessel_types')) {
            Schema::rename('vessel_types', 'vessels');
        }

        if (Schema::hasTable('employee_sea_services') && Schema::hasColumn('employee_sea_services', 'vessel_id')) {
            Schema::table('employee_sea_services', function (Blueprint $table) {
                $table->foreign('vessel_id')->references('id')->on('vessels')->restrictOnDelete();
            });
        }
    }
};
