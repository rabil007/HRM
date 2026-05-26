<?php

namespace App\Http\Requests\Organization\EmployeeDocument\Concerns;

use App\Models\Employee;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;

trait AppliesEmployeeDocumentTemplateRules
{
    /**
     * @param  array<string, mixed>  $baseRules
     * @return array<string, mixed>
     */
    protected function applyEmployeeDocumentTemplateRules(
        array $baseRules,
        bool $wildcard = false,
        string $wildcardPrefix = 'documents.*.',
    ): array {
        $employee = $this->route('employee');

        if (! $employee instanceof Employee) {
            return $baseRules;
        }

        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_documents');

        if ($wildcard) {
            return EmployeeProfileTemplateRequestRules::applyToWildcardRules(
                $employee,
                'employee_documents',
                $baseRules,
                $wildcardPrefix,
            );
        }

        return EmployeeProfileTemplateRequestRules::applyToRules(
            $employee,
            'employee_documents',
            $baseRules,
        );
    }
}
