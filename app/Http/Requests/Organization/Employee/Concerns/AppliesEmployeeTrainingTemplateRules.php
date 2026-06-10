<?php

namespace App\Http\Requests\Organization\Employee\Concerns;

use App\Models\Employee;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Validation\Rule;

trait AppliesEmployeeTrainingTemplateRules
{
    /**
     * @return list<mixed>
     */
    protected function requiredCourseIdRules(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('courses', 'id')->where(fn ($query) => $query->where('is_active', true)),
        ];
    }

    /**
     * @param  array<string, mixed>  $baseRules
     * @return array<string, mixed>
     */
    protected function applyEmployeeTrainingTemplateRules(
        array $baseRules,
        bool $wildcard = false,
        string $wildcardPrefix = 'trainings.*.',
    ): array {
        $employee = $this->route('employee');

        if (! $employee instanceof Employee) {
            return $baseRules;
        }

        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_trainings');

        if ($wildcard) {
            return EmployeeProfileTemplateRequestRules::applyToWildcardRules(
                $employee,
                'employee_trainings',
                $baseRules,
                $wildcardPrefix,
            );
        }

        return EmployeeProfileTemplateRequestRules::applyToRules(
            $employee,
            'employee_trainings',
            $baseRules,
        );
    }
}
