<?php

namespace App\Support\EmployeeProfileTemplates;

use App\Imports\EmployeesImport;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;

final class EmployeeProfileTemplateImportFields
{
    /**
     * Registry employee field => import column name (must exist in EmployeesImport::FIELD_ALIASES).
     *
     * @var array<string, string>
     */
    private const EMPLOYEE_FIELD_TO_IMPORT = [
        'employee_no' => 'employee_no',
        'name' => 'name',
        'work_email' => 'work_email',
        'personal_email' => 'personal_email',
        'phone' => 'phone',
        'phone_home_country' => 'phone_home_country',
        'date_of_birth' => 'date_of_birth',
        'hire_date' => 'hire_date',
        'place_of_birth' => 'place_of_birth',
        'marital_status' => 'marital_status',
        'spouse_name' => 'spouse_name',
        'address' => 'address',
        'nearest_airport' => 'nearest_airport',
        'emergency_contact' => 'emergency_contact',
        'emergency_phone' => 'emergency_phone',
        'passport_number' => 'passport_number',
        'emirates_id' => 'emirates_id',
        'branch_id' => 'branch',
        'department_id' => 'department',
        'position_id' => 'position',
        'project_id' => 'project',
        'client_id' => 'client',
        'gender_id' => 'gender',
        'religion_id' => 'religion',
        'nationality_id' => 'nationality',
        'rank_id' => 'rank',
        'visa_type_id' => 'visa_type',
        'company_visa_type_id' => 'sponsor',
        'status' => 'status',
    ];

    /**
     * @return list<string>
     */
    public static function columnsForTemplate(?EmployeeProfileTemplate $template): array
    {
        if ($template === null) {
            return EmployeesImport::TEMPLATE_HEADERS;
        }

        $resolved = EmployeeProfileTemplateResolver::resolve($template);
        $columns = ['employee_no', 'name'];

        foreach ($resolved['fields']['employees'] ?? [] as $key => $field) {
            if (($field['visible'] ?? false) !== true) {
                continue;
            }

            $importKey = self::EMPLOYEE_FIELD_TO_IMPORT[$key] ?? null;

            if ($importKey !== null && ! in_array($importKey, $columns, true)) {
                $columns[] = $importKey;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return list<string>
     */
    public static function requiredImportFieldsForTemplate(?EmployeeProfileTemplate $template): array
    {
        if ($template === null) {
            return EmployeesImport::REQUIRED_FIELDS;
        }

        $employee = new Employee;
        $employee->setRelation('employeeProfileTemplate', $template);

        $required = [];

        foreach (EmployeesImport::importFieldTemplateMap() as $importField => [$table, $fieldKey]) {
            if (EmployeeProfileTemplateRequestRules::isFieldRequired($employee, $table, $fieldKey)) {
                $required[] = $importField;
            }
        }

        if ($required === []) {
            return EmployeesImport::REQUIRED_FIELDS;
        }

        return array_values(array_unique($required));
    }
}
