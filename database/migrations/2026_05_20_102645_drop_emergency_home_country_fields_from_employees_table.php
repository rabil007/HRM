<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $removedFieldKeys = [
        'emergency_contact_home_country',
        'emergency_phone_home_country',
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

        $columnsToDrop = array_values(array_filter(
            $this->removedFieldKeys,
            fn (string $column): bool => Schema::hasColumn('employees', $column),
        ));

        if ($columnsToDrop !== []) {
            Schema::table('employees', function (Blueprint $table) use ($columnsToDrop): void {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'emergency_contact_home_country')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('emergency_contact_home_country', 200)->nullable()->after('emergency_phone');
            });
        }

        if (! Schema::hasColumn('employees', 'emergency_phone_home_country')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('emergency_phone_home_country', 30)->nullable()->after('emergency_contact_home_country');
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
