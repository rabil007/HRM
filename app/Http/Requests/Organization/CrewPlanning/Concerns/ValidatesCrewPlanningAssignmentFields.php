<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\VesselManning;
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
            $companyId = (int) $this->attributes->get('current_company_id');

            $assignment = $this->route('assignment');
            $existing = $assignment instanceof CrewPlanningAssignment ? $assignment : null;

            if (! $validator->errors()->hasAny(['vessel_id', 'rank_id'])) {
                $vesselId = $this->has('vessel_id')
                    ? $this->input('vessel_id')
                    : $existing?->vessel_id;
                $rankId = $this->has('rank_id')
                    ? $this->input('rank_id')
                    : $existing?->rank_id;

                if ($vesselId !== null && $rankId !== null) {
                    $isManningConfigured = VesselManning::query()
                        ->where('company_id', $companyId)
                        ->where('vessel_id', $vesselId)
                        ->where('rank_id', $rankId)
                        ->exists();

                    if (! $isManningConfigured) {
                        $validator->errors()->add(
                            'rank_id',
                            'This rank is not configured for the selected vessel in Vessel Manning.',
                        );
                    }
                }
            }

            if ($validator->errors()->hasAny(['employee_id', 'rank_id'])) {
                return;
            }

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
