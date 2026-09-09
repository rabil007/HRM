<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-scoped lookup for an employee's current actual On Vessel assignment.
 *
 * Planned future assignments and completed historical tours are not current.
 */
final class ActiveOnVesselAssignmentFinder
{
    /**
     * A likely transfer is only a move onto a different vessel while On Vessel.
     *
     * @param  array{vessel_id: int|null}|null  $current
     */
    public function recommendsTransfer(?array $current, ?int $destinationVesselId): bool
    {
        if ($current === null || $destinationVesselId === null || $destinationVesselId < 1) {
            return false;
        }

        $currentVesselId = $current['vessel_id'] ?? null;

        return $currentVesselId === null || (int) $currentVesselId !== $destinationVesselId;
    }

    /**
     * @return array{
     *     assignment_id: int,
     *     assignment_no: string,
     *     employee_id: int,
     *     employee_name: string,
     *     vessel_id: int|null,
     *     vessel_name: string|null,
     *     phase_id: int,
     *     actual_start_at: string|null,
     *     actual_start_display: string|null,
     *     status: string
     * }|null
     */
    public function find(int $companyId, int $employeeId, ?int $exceptAssignmentId = null): ?array
    {
        if ($companyId < 1 || $employeeId < 1) {
            return null;
        }

        $assignment = $this->query($companyId, $exceptAssignmentId)
            ->where('employee_id', $employeeId)
            ->first();

        if ($assignment === null) {
            return null;
        }

        return $this->present($assignment, $companyId);
    }

    /**
     * @return array<int, array{
     *     assignment_id: int,
     *     assignment_no: string,
     *     employee_id: int,
     *     employee_name: string,
     *     vessel_id: int|null,
     *     vessel_name: string|null,
     *     phase_id: int,
     *     actual_start_at: string|null,
     *     actual_start_display: string|null,
     *     status: string
     * }>
     */
    public function forCompany(int $companyId): array
    {
        if ($companyId < 1) {
            return [];
        }

        $indexed = [];

        foreach ($this->query($companyId)->get() as $assignment) {
            $presented = $this->present($assignment, $companyId);
            $indexed[$presented['employee_id']] = $presented;
        }

        return $indexed;
    }

    /**
     * @return Builder<CrewAssignment>
     */
    private function query(int $companyId, ?int $exceptAssignmentId = null)
    {
        $query = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereHas('employee', function ($employee) use ($companyId): void {
                $employee->where('company_id', $companyId);
            })
            ->whereHas('currentPhase', function ($phase) use ($companyId): void {
                $phase->where('company_id', $companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->where('status', CrewPhaseStatus::Active)
                    ->whereNotNull('actual_start_at');
            })
            ->with([
                'employee:id,company_id,name',
                'vessel:id,company_id,name',
                'currentPhase:id,crew_assignment_id,company_id,phase_code,status,actual_start_at',
            ]);

        if ($exceptAssignmentId !== null) {
            $query->whereKeyNot($exceptAssignmentId);
        }

        return $query;
    }

    /**
     * @return array{
     *     assignment_id: int,
     *     assignment_no: string,
     *     employee_id: int,
     *     employee_name: string,
     *     vessel_id: int|null,
     *     vessel_name: string|null,
     *     phase_id: int,
     *     actual_start_at: string|null,
     *     actual_start_display: string|null,
     *     status: string
     * }
     */
    private function present(CrewAssignment $assignment, int $companyId): array
    {
        $phase = $assignment->currentPhase;
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $start = $phase?->actual_start_at;
        $employee = $assignment->employee;
        $vessel = $assignment->vessel;
        $sameCompanyEmployee = $employee !== null && (int) $employee->company_id === $companyId;
        $sameCompanyVessel = $vessel !== null && (int) $vessel->company_id === $companyId;

        return [
            'assignment_id' => (int) $assignment->id,
            'assignment_no' => (string) $assignment->assignment_no,
            'employee_id' => (int) $assignment->employee_id,
            'employee_name' => $sameCompanyEmployee ? (string) $employee->name : 'This employee',
            'vessel_id' => $sameCompanyVessel ? (int) $vessel->id : null,
            'vessel_name' => $sameCompanyVessel ? $vessel->name : null,
            'phase_id' => (int) ($phase?->id ?? 0),
            'actual_start_at' => $start instanceof CarbonInterface
                ? $start->timezone($timezone)->toIso8601String()
                : null,
            'actual_start_display' => $start instanceof CarbonInterface
                ? $start->timezone($timezone)->format('j M Y H:i')
                : null,
            'status' => $assignment->status->value,
        ];
    }
}
