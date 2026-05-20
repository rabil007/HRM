<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('onboarding_templates')->orderBy('id')->each(function ($row): void {
            $tasks = json_decode($row->tasks, true);

            if (! is_array($tasks)) {
                return;
            }

            $changed = false;

            if (isset($tasks['stages']) && is_array($tasks['stages'])) {
                foreach ($tasks['stages'] as &$stage) {
                    if (! is_array($stage)) {
                        continue;
                    }

                    if (isset($stage['employee_fields']) && is_array($stage['employee_fields'])) {
                        $changed = $this->removeCvSourceFromFields($stage['employee_fields']) || $changed;
                    }

                    if (isset($stage['modules']['profile']['required_fields']) && is_array($stage['modules']['profile']['required_fields'])) {
                        $changed = $this->removeCvSourceFromRequiredFields($stage['modules']['profile']['required_fields']) || $changed;
                    }
                }

                unset($stage);
            }

            if (isset($tasks['modules']['profile']['required_fields']) && is_array($tasks['modules']['profile']['required_fields'])) {
                $changed = $this->removeCvSourceFromRequiredFields($tasks['modules']['profile']['required_fields']) || $changed;
            }

            if ($changed) {
                DB::table('onboarding_templates')
                    ->where('id', $row->id)
                    ->update(['tasks' => json_encode($tasks)]);
            }
        });

        if (Schema::hasColumn('employees', 'cv_source')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('cv_source');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'cv_source')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('cv_source', 120)->nullable()->after('phone_home_country');
            });
        }
    }

    /**
     * @param  array<int|string, mixed>  $fields
     */
    private function removeCvSourceFromFields(array &$fields): bool
    {
        $filtered = [];
        $changed = false;

        foreach ($fields as $field) {
            if (is_string($field) && $field === 'cv_source') {
                $changed = true;

                continue;
            }

            if (is_array($field) && ($field['key'] ?? '') === 'cv_source') {
                $changed = true;

                continue;
            }

            $filtered[] = $field;
        }

        if ($changed) {
            $fields = $filtered;
        }

        return $changed;
    }

    /**
     * @param  array<int|string, mixed>  $requiredFields
     */
    private function removeCvSourceFromRequiredFields(array &$requiredFields): bool
    {
        $filtered = array_values(array_filter(
            $requiredFields,
            fn ($key) => $key !== 'cv_source',
        ));

        if (count($filtered) === count($requiredFields)) {
            return false;
        }

        $requiredFields = $filtered;

        return true;
    }
};
