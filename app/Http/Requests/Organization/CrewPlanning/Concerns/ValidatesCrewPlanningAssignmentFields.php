<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesCrewPlanningAssignmentFields
{
    /**
     * @return array<string, mixed>
     */
    protected function crewPlanningEmployeeIdRule(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'nullable',
            'integer',
            Rule::exists('employees', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->whereNotNull('rank_id')),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['employee_id', 'rank_id'])) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');

            $assignment = $this->route('assignment');
            $existing = $assignment instanceof CrewPlanningAssignment ? $assignment : null;

            $rawEmployeeId = $this->has('employee_id')
                ? $this->input('employee_id')
                : $existing?->employee_id;

            if ($rawEmployeeId === null || $rawEmployeeId === '') {
                return;
            }

            $employeeId = (int) $rawEmployeeId;

            $assignmentRankId = $this->has('rank_id')
                ? $this->input('rank_id')
                : $existing?->rank_id;

            if ($assignmentRankId === null || $assignmentRankId === '') {
                return;
            }

            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->first(['rank_id']);

            if ($employee !== null && (int) $employee->rank_id !== (int) $assignmentRankId) {
                $validator->errors()->add(
                    'employee_id',
                    'The crew member\'s profile rank must match the selected rank.',
                );
            }
        });
    }
}
