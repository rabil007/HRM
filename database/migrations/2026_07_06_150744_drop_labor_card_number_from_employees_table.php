<?php

use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $removedFieldKeys = [
        'labor_card_number',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('employees', 'labor_card_number')) {
            Employee::query()
                ->whereNotNull('labor_card_number')
                ->where('labor_card_number', '!=', '')
                ->orderBy('id')
                ->each(function (Employee $employee): void {
                    $contract = $employee->contracts()
                        ->where('status', 'active')
                        ->orderByDesc('id')
                        ->first();

                    if ($contract === null) {
                        return;
                    }

                    if (filled($contract->labor_contract_id)) {
                        return;
                    }

                    $contract->update([
                        'labor_contract_id' => $employee->labor_card_number,
                    ]);
                });
        }

        EmployeeProfileTemplate::query()->each(function (EmployeeProfileTemplate $template): void {
            $configuration = $template->configuration_json;

            if (! is_array($configuration)) {
                return;
            }

            unset($configuration['fields']['employees']['labor_card_number']);

            $template->update(['configuration_json' => $configuration]);
        });

        if (Schema::hasTable('onboarding_templates')) {
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
        }

        if (Schema::hasColumn('employees', 'labor_card_number')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('labor_card_number');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'labor_card_number')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('labor_card_number', 100)->nullable()->after('passport_number');
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
