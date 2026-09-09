<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignmentPhase;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;

/**
 * Rejects a genuine overlap between actual On Vessel intervals for one employee.
 *
 * Intervals are half-open [start, end). An exact timestamp handoff is valid.
 * A missing end means the phase is still open. Planned dates are ignored.
 */
final class OnVesselActualIntervalGuard
{
    public function assertNoOverlap(
        int $companyId,
        int $employeeId,
        CarbonInterface $start,
        ?CarbonInterface $end,
        ?int $exceptAssignmentId = null,
        ?int $exceptPhaseId = null,
        string $context = 'movement',
    ): void {
        if ($companyId < 1 || $employeeId < 1) {
            return;
        }

        if ($end !== null && $end->lt($start)) {
            return;
        }

        $conflict = $this->conflictingPhase(
            $companyId,
            $employeeId,
            $start,
            $end,
            $exceptAssignmentId,
            $exceptPhaseId,
        );

        if ($conflict === null) {
            return;
        }

        throw CrewMovementException::make(
            $this->message($conflict, $companyId, $start, $end, $context),
            $context === 'correction'
                ? 'correction_on_vessel_overlap'
                : 'on_vessel_interval_overlap',
        );
    }

    public function overlaps(
        CarbonInterface $leftStart,
        ?CarbonInterface $leftEnd,
        CarbonInterface $rightStart,
        ?CarbonInterface $rightEnd,
    ): bool {
        $leftStartsBeforeRightEnds = $rightEnd === null || $leftStart->lt($rightEnd);
        $rightStartsBeforeLeftEnds = $leftEnd === null || $rightStart->lt($leftEnd);

        return $leftStartsBeforeRightEnds && $rightStartsBeforeLeftEnds;
    }

    private function conflictingPhase(
        int $companyId,
        int $employeeId,
        CarbonInterface $start,
        ?CarbonInterface $end,
        ?int $exceptAssignmentId,
        ?int $exceptPhaseId,
    ): ?CrewAssignmentPhase {
        $phases = CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->where('phase_code', CrewPhaseCode::OnVessel)
            ->whereIn('status', [CrewPhaseStatus::Active, CrewPhaseStatus::Completed])
            ->whereNotNull('actual_start_at')
            ->when($exceptPhaseId !== null, fn ($query) => $query->whereKeyNot($exceptPhaseId))
            ->whereHas('assignment', function ($query) use ($companyId, $employeeId, $exceptAssignmentId): void {
                $query->where('company_id', $companyId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', [
                        CrewAssignmentStatus::Active,
                        CrewAssignmentStatus::Completed,
                    ]);

                if ($exceptAssignmentId !== null) {
                    $query->whereKeyNot($exceptAssignmentId);
                }
            })
            ->with([
                'assignment:id,company_id,employee_id,assignment_no,vessel_id,status',
                'assignment.employee:id,company_id,name',
                'assignment.vessel:id,company_id,name',
            ])
            ->orderBy('actual_start_at')
            ->get();

        foreach ($phases as $phase) {
            if (! $phase->actual_start_at instanceof CarbonInterface) {
                continue;
            }

            if ($this->overlaps($start, $end, $phase->actual_start_at, $phase->actual_end_at)) {
                return $phase;
            }
        }

        return null;
    }

    private function message(
        CrewAssignmentPhase $conflict,
        int $companyId,
        CarbonInterface $start,
        ?CarbonInterface $end,
        string $context,
    ): string {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $assignment = $conflict->assignment;
        $employeeName = (string) ($assignment?->employee?->name ?? 'This employee');
        $vesselName = $assignment?->vessel?->name ?? 'another vessel';
        $assignmentNo = (string) ($assignment?->assignment_no ?? 'the current assignment');
        $conflictStart = $conflict->actual_start_at instanceof CarbonInterface
            ? $this->format($conflict->actual_start_at, $timezone)
            : $this->format($start, $timezone);
        $conflictEnd = $conflict->actual_end_at instanceof CarbonInterface
            ? $this->format($conflict->actual_end_at, $timezone)
            : 'still onboard';
        $periodStart = $this->format($start, $timezone);
        $periodEnd = $end instanceof CarbonInterface
            ? $this->format($end, $timezone)
            : 'still onboard';

        if ($context === 'correction') {
            return implode("\n\n", [
                'This correction would overlap another crew assignment.',
                "Current assignment:\n{$assignmentNo}\n{$vesselName}",
                "Conflicting period:\n{$periodStart} – {$periodEnd}",
                'Correct the previous assignment end first, or use the appropriate Transfer Vessel workflow for a direct handoff.',
            ]);
        }

        return implode("\n\n", [
            'This movement overlaps an existing On Vessel assignment.',
            "{$employeeName} is still recorded On Vessel on {$vesselName} during this time ({$conflictStart} – {$conflictEnd}).",
            'Use Transfer Vessel for a direct vessel handoff, or correct the previous movement dates before continuing.',
        ]);
    }

    private function format(CarbonInterface $value, string $timezone): string
    {
        return $value->timezone($timezone)->format('j M Y H:i');
    }
}
