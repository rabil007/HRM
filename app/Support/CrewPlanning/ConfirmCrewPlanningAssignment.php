<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Support\CrewDeployments\SyncSeaServiceFromDeployment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmCrewPlanningAssignment
{
    public function __construct(
        private SyncSeaServiceFromDeployment $syncSeaService,
    ) {}

    public function handle(CrewPlanningAssignment $assignment, int $companyId): EmployeeDeployment
    {
        return DB::transaction(function () use ($assignment, $companyId): EmployeeDeployment {
            $assignment = CrewPlanningAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->company_id !== $companyId) {
                abort(404);
            }

            if ($assignment->status !== 'draft') {
                throw ValidationException::withMessages([
                    'assignment' => 'Only draft assignments can be confirmed.',
                ]);
            }

            if ($assignment->employee_id === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Assign a crew member before confirming.',
                ]);
            }

            if ($assignment->vessel_id === null || $assignment->rank_id === null) {
                throw ValidationException::withMessages([
                    'assignment' => 'Vessel and rank are required before confirming.',
                ]);
            }

            $joinDate = $assignment->planned_join_date->toDateString();
            $leaveDate = $assignment->planned_leave_date->toDateString();

            if (CrewPlanningOverlapValidator::employeeIsDoubleBooked(
                $companyId,
                $assignment->employee_id,
                $joinDate,
                $leaveDate,
                $assignment->id,
            )) {
                throw ValidationException::withMessages([
                    'planned_join_date' => 'This employee is already assigned for overlapping dates.',
                ]);
            }

            $employee = Employee::query()
                ->whereKey($assignment->employee_id)
                ->where('company_id', $companyId)
                ->firstOrFail();

            $maxSort = EmployeeDeployment::query()
                ->where('employee_id', $employee->id)
                ->where('company_id', $companyId)
                ->max('sort_order');

            $deployment = EmployeeDeployment::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
                'rank_id' => $assignment->rank_id,
                'vessel_id' => $assignment->vessel_id,
                'joined_date' => $joinDate,
                'disembarked_date' => $leaveDate,
                'remarks' => $assignment->notes,
            ]);

            $assignment->update([
                'status' => 'confirmed',
                'employee_deployment_id' => $deployment->id,
            ]);

            $this->syncSeaService->sync($deployment);

            return $deployment;
        });
    }
}
