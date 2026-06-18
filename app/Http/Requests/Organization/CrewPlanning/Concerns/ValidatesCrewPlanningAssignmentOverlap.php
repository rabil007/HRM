<?php

namespace App\Http\Requests\Organization\CrewPlanning\Concerns;

use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\CrewPlanningOverlapValidator;
use Illuminate\Validation\Validator;

trait ValidatesCrewPlanningAssignmentOverlap
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['employee_id', 'planned_join_date', 'planned_leave_date'])) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');

            $assignment = $this->route('assignment');
            $existing = $assignment instanceof CrewPlanningAssignment ? $assignment : null;

            // On drag updates only dates are sent — fall back to the stored employee_id.
            $rawEmployeeId = $this->has('employee_id')
                ? $this->input('employee_id')
                : $existing?->employee_id;

            if ($rawEmployeeId === null || $rawEmployeeId === '') {
                return;
            }
            $employeeId = (int) $rawEmployeeId;

            // Fall back to stored dates for partial drag payloads.
            $joinDate = $this->input('planned_join_date', $existing?->planned_join_date?->toDateString());
            $leaveDate = $this->input('planned_leave_date', $existing?->planned_leave_date?->toDateString());

            if ($joinDate === null || $leaveDate === null) {
                return;
            }

            $excludeId = $existing?->id;

            if (CrewPlanningOverlapValidator::employeeIsDoubleBooked(
                $companyId,
                $employeeId,
                $joinDate,
                $leaveDate,
                $excludeId,
            )) {
                $validator->errors()->add(
                    'planned_join_date',
                    'This employee is already assigned for overlapping dates.',
                );
            }
        });
    }
}
