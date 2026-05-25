<?php

namespace App\Support\EmployeeProfileTemplates;

use App\Imports\EmployeesImport;
use App\Models\EmployeeProfileTemplate;

final class EmployeeProfileTemplateImportFields
{
    /** @var array<string, string> */
    private const TEMPLATE_KEY_TO_IMPORT = [
        'work_email' => 'work_email',
        'phone' => 'phone',
        'gender_id' => 'gender',
        'religion_id' => 'religion',
        'nationality_id' => 'nationality',
        'branch_id' => 'branch',
        'department_id' => 'department',
        'position_id' => 'position',
        'manager_id' => 'manager_employee_no',
        'bank_id' => 'bank',
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
            if (($field['visible'] ?? false) && isset(self::TEMPLATE_KEY_TO_IMPORT[$key])) {
                $columns[] = self::TEMPLATE_KEY_TO_IMPORT[$key];
            }
        }

        foreach ($resolved['fields']['employee_contracts'] ?? [] as $key => $field) {
            if ($field['visible'] ?? false) {
                $columns[] = $key;
            }
        }

        foreach ($resolved['fields']['employee_bank_accounts'] ?? [] as $key => $field) {
            if (($field['visible'] ?? false) && $key === 'bank_id') {
                $columns[] = 'bank';
            }
            if (($field['visible'] ?? false) && in_array($key, ['iban', 'account_name'], true)) {
                $columns[] = $key;
            }
        }

        return array_values(array_unique($columns));
    }
}
