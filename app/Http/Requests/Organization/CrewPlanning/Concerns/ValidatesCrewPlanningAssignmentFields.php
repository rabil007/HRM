<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewPlanning\ValidatesCrewPlanningReliefLink;
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
                ->where('status', 'active')
                ->whereNotNull('rank_id')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function crewPlanningRelievesAssignmentIdRule(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'nullable',
            'integer',
            Rule::exists('crew_assignments', 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = (int) $this->attributes->get('current_company_id');

            $assignment = $this->route('assignment');
            $existing = $assignment instanceof CrewPlanningAssignment ? $assignment : null;

            $rawEmployeeId = $this->has('employee_id')
                ? $this->input('employee_id')
                : $existing?->employee_id;

            $assignmentRankId = $this->has('rank_id')
                ? $this->input('rank_id')
                : $existing?->rank_id;

            // Employee-specific rules only when a relief/planned employee is selected.
            if ($rawEmployeeId !== null && $rawEmployeeId !== ''
                && $assignmentRankId !== null && $assignmentRankId !== ''
                && ! $validator->errors()->hasAny(['employee_id', 'rank_id'])) {
                $employee = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $rawEmployeeId)
                    ->first(['rank_id']);

                if ($employee !== null && (int) $employee->rank_id !== (int) $assignmentRankId) {
                    $validator->errors()->add(
                        'employee_id',
                        'The crew member\'s profile rank must match the selected rank.',
                    );
                }
            }

            // Relief link rules always apply when present — including vacant slots.
            ValidatesCrewPlanningReliefLink::validate($validator, [
                'company_id' => $companyId,
                'relieves_crew_assignment_id' => $this->has('relieves_crew_assignment_id')
                    ? $this->input('relieves_crew_assignment_id')
                    : $existing?->relieves_crew_assignment_id,
                'vessel_id' => $this->has('vessel_id')
                    ? $this->input('vessel_id')
                    : $existing?->vessel_id,
                'rank_id' => $assignmentRankId,
                'employee_id' => $rawEmployeeId,
            ], $existing);
        });
    }
}
