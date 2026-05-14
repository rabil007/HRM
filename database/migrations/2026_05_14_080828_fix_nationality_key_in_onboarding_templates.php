<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename legacy field keys in onboarding_templates.tasks JSON:
 *   "nationality"  → "nationality_id"
 *   "gender"       → "gender_id"
 *   "religion"     → "religion_id"
 *   "bank"         → "bank_id"
 *   "branch"       → "branch_id"
 *   "department"   → "department_id"
 *   "position"     → "position_id"
 *   "manager"      → "manager_id"
 */
return new class extends Migration
{
    private array $keyMap = [
        'nationality' => 'nationality_id',
        'gender' => 'gender_id',
        'religion' => 'religion_id',
        'bank' => 'bank_id',
        'branch' => 'branch_id',
        'department' => 'department_id',
        'position' => 'position_id',
        'manager' => 'manager_id',
    ];

    public function up(): void
    {
        DB::table('onboarding_templates')->orderBy('id')->each(function ($row) {
            $tasks = json_decode($row->tasks, true);

            if (! is_array($tasks) || ! isset($tasks['stages'])) {
                return;
            }

            $changed = false;

            foreach ($tasks['stages'] as &$stage) {
                foreach (['employee_fields', 'bank_account_fields', 'contract_fields'] as $section) {
                    if (! isset($stage[$section]) || ! is_array($stage[$section])) {
                        continue;
                    }

                    foreach ($stage[$section] as &$field) {
                        if (! is_array($field) || ! isset($field['key'])) {
                            continue;
                        }

                        $mapped = $this->keyMap[$field['key']] ?? null;

                        if ($mapped !== null) {
                            $field['key'] = $mapped;
                            $changed = true;
                        }
                    }

                    unset($field);
                }

                if (isset($stage['modules']['profile']['required_fields']) && is_array($stage['modules']['profile']['required_fields'])) {
                    foreach ($stage['modules']['profile']['required_fields'] as &$key) {
                        $mapped = $this->keyMap[$key] ?? null;

                        if ($mapped !== null) {
                            $key = $mapped;
                            $changed = true;
                        }
                    }

                    unset($key);
                }
            }

            unset($stage);

            if (isset($tasks['modules']['profile']['required_fields']) && is_array($tasks['modules']['profile']['required_fields'])) {
                foreach ($tasks['modules']['profile']['required_fields'] as &$key) {
                    $mapped = $this->keyMap[$key] ?? null;

                    if ($mapped !== null) {
                        $key = $mapped;
                        $changed = true;
                    }
                }

                unset($key);
            }

            if ($changed) {
                DB::table('onboarding_templates')
                    ->where('id', $row->id)
                    ->update(['tasks' => json_encode($tasks)]);
            }
        });
    }

    public function down(): void {}
};
