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
        'company_visa_type_id' => 'company_visa_type',
        'status' => 'status',
    ];

    /**
     * @return list<string>
     */
    public static function columnsForTemplate(?EmployeeProfileTemplate $template): array
    {
        if ($template === null) {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-351a82.log'), json_encode(['sessionId' => '351a82', 'runId' => 'post-fix', 'hypothesisId' => 'C', 'location' => 'EmployeeProfileTemplateImportFields.php:columnsForTemplate', 'message' => 'null template using default headers', 'data' => ['headers' => EmployeesImport::TEMPLATE_HEADERS, 'has_sponsor_alias' => array_key_exists('company_visa_type_id', self::EMPLOYEE_FIELD_TO_IMPORT)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion

            return EmployeesImport::TEMPLATE_HEADERS;
        }

        $resolved = EmployeeProfileTemplateResolver::resolve($template);
        $columns = ['employee_no', 'name'];
        $visibleEmployeeKeys = [];
        $skippedUnmapped = [];

        foreach ($resolved['fields']['employees'] ?? [] as $key => $field) {
            if (($field['visible'] ?? false) !== true) {
                continue;
            }

            $visibleEmployeeKeys[] = $key;

            $importKey = self::EMPLOYEE_FIELD_TO_IMPORT[$key] ?? null;

            if ($importKey === null) {
                $skippedUnmapped[] = $key;
            }

            if ($importKey !== null && ! in_array($importKey, $columns, true)) {
                $columns[] = $importKey;
            }
        }

        $columns = array_values(array_unique($columns));

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-351a82.log'), json_encode(['sessionId' => '351a82', 'runId' => 'post-fix', 'hypothesisId' => 'A,B,E', 'location' => 'EmployeeProfileTemplateImportFields.php:columnsForTemplate', 'message' => 'template columns resolved', 'data' => ['template_id' => $template->id, 'template_name' => $template->name, 'visible_employee_keys' => $visibleEmployeeKeys, 'sponsor_visible' => in_array('company_visa_type_id', $visibleEmployeeKeys, true), 'sponsor_in_map' => array_key_exists('company_visa_type_id', self::EMPLOYEE_FIELD_TO_IMPORT), 'skipped_unmapped' => $skippedUnmapped, 'columns' => $columns, 'columns_include_sponsor' => in_array('company_visa_type', $columns, true) || in_array('sponsor', $columns, true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        return $columns;
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
