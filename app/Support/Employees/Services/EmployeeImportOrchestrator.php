<?php

namespace App\Support\Employees\Services;

use App\Imports\EmployeesImport;
use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateImportFields;
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
            'employee_profile_template_id' => [
                'nullable',
                'integer',
                Rule::exists('employee_profile_templates', 'id')->where('company_id', $companyId),
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
        $requiredFields = EmployeeProfileTemplateImportFields::requiredImportFieldsForTemplate(
            $this->resolveProfileTemplateForImport($request),
        );

        return collect($this->importColumnsForRequest($request))
            ->map(function (string $field) use ($permitted, $requiredFields) {
                $permission = EmployeesImport::SENSITIVE_FIELD_PERMISSIONS[$field] ?? null;

                return [
                    'field' => $field,
                    'label' => Str::headline($field),
                    'required' => in_array($field, $requiredFields, true),
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
        return EmployeeProfileTemplateImportFields::columnsForTemplate(
            $this->resolveProfileTemplateForImport($request),
        );
    }

    public function resolveProfileTemplateForImport(Request $request): ?EmployeeProfileTemplate
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $templateId = (int) $request->input(
            'employee_profile_template_id',
            $request->query('profile_template_id', $request->query('template_id', 0)),
        );

        if ($templateId <= 0) {
            return null;
        }

        return EmployeeProfileTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find($templateId);
    }
}
