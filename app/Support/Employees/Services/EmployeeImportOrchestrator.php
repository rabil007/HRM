<?php

namespace App\Support\Employees\Services;

use App\Imports\EmployeesImport;
use App\Models\OnboardingTemplate;
use App\Support\OnboardingTemplateImportFields;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EmployeeImportOrchestrator
{
    /**
     * @return array<string, mixed>
     */
    public function validateImportRequest(Request $request): array
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx,xls',
                'mimetypes:'.implode(',', EmployeesImport::IMPORT_MIME_TYPES),
                'max:10240',
            ],
            'onboarding_template_id' => [
                'required',
                'integer',
                Rule::exists('onboarding_templates', 'id')->where('company_id', $companyId),
            ],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readImportRows(EmployeesImport $importer, mixed $file): array
    {
        $rows = $importer->readRows($file, EmployeesImport::MAX_ROWS + 1);

        if (count($rows) > EmployeesImport::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'The import file may not contain more than '.EmployeesImport::MAX_ROWS.' employee rows.',
            ]);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function allowedImportFields(Request $request): array
    {
        return $this->permittedImportFields($request);
    }

    /**
     * @return list<string>
     */
    public function permittedImportFields(Request $request): array
    {
        $columns = $this->importColumnsForRequest($request);

        return collect($columns)
            ->filter(function (string $field) use ($request) {
                $permission = EmployeesImport::SENSITIVE_FIELD_PERMISSIONS[$field] ?? null;

                return $permission === null || $request->user()?->can($permission);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function importFieldOptions(Request $request): array
    {
        $permitted = array_fill_keys($this->permittedImportFields($request), true);

        return collect($this->importColumnsForRequest($request))
            ->map(function (string $field) use ($permitted) {
                $permission = EmployeesImport::SENSITIVE_FIELD_PERMISSIONS[$field] ?? null;

                return [
                    'field' => $field,
                    'label' => Str::headline($field),
                    'required' => in_array($field, EmployeesImport::REQUIRED_FIELDS, true),
                    'sensitive' => $permission !== null,
                    'permission' => $permission,
                    'allowed' => isset($permitted[$field]),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function importColumnsForRequest(Request $request): array
    {
        $template = $this->resolveOnboardingTemplateForImport($request);

        if ($template === null) {
            return EmployeesImport::TEMPLATE_HEADERS;
        }

        return OnboardingTemplateImportFields::columnsForTasks(
            is_array($template->tasks) ? $template->tasks : null,
        );
    }

    public function resolveOnboardingTemplateForImport(Request $request): ?OnboardingTemplate
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $templateId = (int) $request->input('onboarding_template_id', $request->query('template_id', 0));

        if ($templateId <= 0) {
            return null;
        }

        return OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->find($templateId);
    }
}
