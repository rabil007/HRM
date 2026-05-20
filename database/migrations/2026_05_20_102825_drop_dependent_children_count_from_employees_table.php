<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $removedFieldKeys = [
        'dependent_children_count',
    ];

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
                        $changed = $this->removeFieldKeysFromFields($stage['employee_fields']) || $changed;
                    }

                    if (isset($stage['modules']['profile']['required_fields']) && is_array($stage['modules']['profile']['required_fields'])) {
                        $changed = $this->removeFieldKeysFromRequiredFields($stage['modules']['profile']['required_fields']) || $changed;
                    }
                }

                unset($stage);
            }

            if (isset($tasks['modules']['profile']['required_fields']) && is_array($tasks['modules']['profile']['required_fields'])) {
                $changed = $this->removeFieldKeysFromRequiredFields($tasks['modules']['profile']['required_fields']) || $changed;
            }

            if ($changed) {
                DB::table('onboarding_templates')
                    ->where('id', $row->id)
                    ->update(['tasks' => json_encode($tasks)]);
            }
        });

        if (Schema::hasColumn('employees', 'dependent_children_count')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('dependent_children_count');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'dependent_children_count')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->unsignedSmallInteger('dependent_children_count')->nullable()->after('spouse_birthdate');
            });
        }
    }

    /**
     * @param  array<int|string, mixed>  $fields
     */
    private function removeFieldKeysFromFields(array &$fields): bool
    {
        $removedKeys = array_fill_keys($this->removedFieldKeys, true);
        $filtered = [];
        $changed = false;

        foreach ($fields as $field) {
            if (is_string($field) && isset($removedKeys[$field])) {
                $changed = true;

                continue;
            }

            if (is_array($field) && isset($removedKeys[$field['key'] ?? ''])) {
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
    private function removeFieldKeysFromRequiredFields(array &$requiredFields): bool
    {
        $removedKeys = array_fill_keys($this->removedFieldKeys, true);
        $filtered = array_values(array_filter(
            $requiredFields,
            fn ($key) => ! isset($removedKeys[$key]),
        ));

        if (count($filtered) === count($requiredFields)) {
            return false;
        }

        $requiredFields = $filtered;

        return true;
    }
};
