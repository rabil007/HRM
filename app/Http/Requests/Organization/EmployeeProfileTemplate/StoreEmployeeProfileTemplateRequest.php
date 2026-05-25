<?php

namespace App\Http\Requests\Organization\EmployeeProfileTemplate;

use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEmployeeProfileTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'configuration_json' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $decoded = json_decode((string) $this->input('configuration_json'), true);
            if (! is_array($decoded)) {
                $validator->errors()->add('configuration_json', 'Invalid JSON configuration.');

                return;
            }

            $this->validateConfigurationShape($validator, $decoded);
        });
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function validateConfigurationShape(Validator $validator, array $configuration): void
    {
        $tabs = $configuration['tabs'] ?? null;
        if (! is_array($tabs)) {
            $validator->errors()->add('configuration_json', 'Tabs configuration is required.');

            return;
        }

        foreach (EmployeeProfileTemplateFieldRegistry::TAB_ORDER as $tabKey) {
            if (! isset($tabs[$tabKey]) || ! is_array($tabs[$tabKey])) {
                $validator->errors()->add('configuration_json', "Missing tab configuration for {$tabKey}.");

                continue;
            }
        }

        $fields = $configuration['fields'] ?? null;
        if (! is_array($fields)) {
            $validator->errors()->add('configuration_json', 'Fields configuration is required.');

            return;
        }

        foreach (EmployeeProfileTemplateFieldRegistry::fieldsByTable() as $table => $tableFields) {
            if (! isset($fields[$table]) || ! is_array($fields[$table])) {
                $validator->errors()->add('configuration_json', "Missing fields for {$table}.");

                continue;
            }

            foreach (array_keys($tableFields) as $fieldKey) {
                if (! isset($fields[$table][$fieldKey]) || ! is_array($fields[$table][$fieldKey])) {
                    $validator->errors()->add('configuration_json', "Missing field configuration for {$table}.{$fieldKey}.");
                }
            }
        }
    }
}
