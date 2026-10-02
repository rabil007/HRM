<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeSeaService;
use App\Models\PayrollWorkAllocation;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 4 companion: retire soft-deleted CrewAssignment(status=planned) tombstones.
 *
 * Soft-deleted Planned rows are never converted into CrewPlanningAssignment.
 * This only normalizes obsolete legacy status vocabulary to Cancelled while
 * preserving deleted_at and historical auditability.
 */
final class RetireSoftDeletedPlannedAssignments
{
    public const VERSION = 'phase4-soft-deleted-v1';

    public const ACTIVITY = 'legacy_soft_deleted_planned_retired';

    /**
     * @param  list<int>  $companyIds
     */
    public function inspect(array $companyIds, ?int $assignmentId = null): SoftDeletedPlannedRetirementReport
    {
        $candidates = $this->buildCandidates($companyIds, $assignmentId);

        return $this->makeReport(
            $candidates,
            applied: false,
            abortedDueToBlockers: false,
            companyIds: $companyIds,
            assignmentId: $assignmentId,
        );
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function apply(array $companyIds, ?int $assignmentId = null, ?User $actor = null): SoftDeletedPlannedRetirementReport
    {
        $candidates = $this->buildCandidates($companyIds, $assignmentId);

        $blocked = array_filter(
            $candidates,
            fn (SoftDeletedPlannedRetirementCandidate $c): bool => $c->migrationStatus === SoftDeletedPlannedRetirementCandidate::STATUS_BLOCKED,
        );

        if ($blocked !== []) {
            return $this->makeReport(
                $candidates,
                applied: false,
                abortedDueToBlockers: true,
                companyIds: $companyIds,
                assignmentId: $assignmentId,
            );
        }

        $retired = 0;

        foreach ($candidates as $index => $candidate) {
            if ($candidate->migrationStatus !== SoftDeletedPlannedRetirementCandidate::STATUS_CONVERTIBLE) {
                continue;
            }

            try {
                $candidates[$index] = $this->retireOne($candidate, $actor);
                $retired++;
            } catch (Throwable $exception) {
                $candidates[$index] = new SoftDeletedPlannedRetirementCandidate(
                    assignmentId: $candidate->assignmentId,
                    assignmentNo: $candidate->assignmentNo,
                    companyId: $candidate->companyId,
                    employeeId: $candidate->employeeId,
                    employeeName: $candidate->employeeName,
                    vesselId: $candidate->vesselId,
                    vesselName: $candidate->vesselName,
                    positionId: $candidate->positionId,
                    positionName: $candidate->positionName,
                    deletedAt: $candidate->deletedAt,
                    closedAt: $candidate->closedAt,
                    migrationStatus: SoftDeletedPlannedRetirementCandidate::STATUS_FAILED,
                    disposition: $candidate->disposition,
                    blockers: $candidate->blockers,
                    failureReason: $exception->getMessage(),
                );
            }
        }

        return $this->makeReport(
            $candidates,
            applied: true,
            abortedDueToBlockers: false,
            companyIds: $companyIds,
            assignmentId: $assignmentId,
            retiredCount: $retired,
        );
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<SoftDeletedPlannedRetirementCandidate>
     */
    private function buildCandidates(array $companyIds, ?int $assignmentId): array
    {
        $query = CrewAssignment::withTrashed()
            ->whereNotNull('deleted_at')
            ->where('status', CrewAssignmentStatus::Planned)
            ->whereIn('company_id', $companyIds)
            ->with([
                'employee:id,name,company_id',
                'vessel:id,name,company_id',
                'position:id,title,company_id',
                'phases' => fn ($q) => $q->withTrashed(),
                'timesheetPreparationLines:id,crew_assignment_id',
                'accommodationStays:id,crew_assignment_id',
                'corrections:id,crew_assignment_id,status',
            ])
            ->orderBy('company_id')
            ->orderBy('id');

        if ($assignmentId !== null) {
            $query->whereKey($assignmentId);
        }

        return $query->get()
            ->map(fn (CrewAssignment $assignment): SoftDeletedPlannedRetirementCandidate => $this->classify($assignment))
            ->values()
            ->all();
    }

    private function classify(CrewAssignment $assignment): SoftDeletedPlannedRetirementCandidate
    {
        $base = [
            'assignmentId' => (int) $assignment->id,
            'assignmentNo' => (string) $assignment->assignment_no,
            'companyId' => (int) $assignment->company_id,
            'employeeId' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
            'employeeName' => $assignment->employee?->name,
            'vesselId' => $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null,
            'vesselName' => $assignment->vessel?->name,
            'positionId' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
            'positionName' => $assignment->position?->title,
            'deletedAt' => $assignment->deleted_at?->toDateTimeString(),
            'closedAt' => $assignment->closed_at?->toDateTimeString(),
        ];

        $blockers = $this->detectBlockers($assignment);

        if ($blockers !== []) {
            return new SoftDeletedPlannedRetirementCandidate(
                ...$base,
                migrationStatus: SoftDeletedPlannedRetirementCandidate::STATUS_BLOCKED,
                disposition: SoftDeletedPlannedRetirementCandidate::DISPOSITION_NONE,
                blockers: $blockers,
            );
        }

        return new SoftDeletedPlannedRetirementCandidate(
            ...$base,
            migrationStatus: SoftDeletedPlannedRetirementCandidate::STATUS_CONVERTIBLE,
            disposition: SoftDeletedPlannedRetirementCandidate::DISPOSITION_RETIRE_TO_CANCELLED,
            blockers: [],
        );
    }

    /**
     * @return list<string>
     */
    private function detectBlockers(CrewAssignment $assignment): array
    {
        $blockers = [];

        if (! $assignment->trashed() || $assignment->deleted_at === null) {
            $blockers[] = 'not_soft_deleted';
        }

        if ($assignment->status !== CrewAssignmentStatus::Planned) {
            $blockers[] = 'unexpected_assignment_status';
        }

        if ($assignment->started_at !== null) {
            $blockers[] = 'unexpected_started_at';
        }

        $phases = $assignment->relationLoaded('phases')
            ? $assignment->phases
            : $assignment->phases()->withTrashed()->get();

        if ($phases->count() === 0) {
            $blockers[] = 'missing_p0_phase';
        } elseif ($phases->count() > 1) {
            $blockers[] = 'unexpected_multiple_phases';
        } else {
            /** @var CrewAssignmentPhase $phase */
            $phase = $phases->first();

            if ($phase->phase_code === CrewPhaseCode::OnVessel) {
                $blockers[] = 'unexpected_p4_history';
            } elseif ($phase->phase_code !== CrewPhaseCode::PreMobilisation) {
                $blockers[] = 'unexpected_non_p0_phase';
            }

            if ($phase->status !== CrewPhaseStatus::Planned) {
                $blockers[] = 'unexpected_phase_status';
            }

            if ($phase->actual_start_at !== null || $phase->actual_end_at !== null) {
                $blockers[] = 'unexpected_phase_actuals';
            }
        }

        $phaseIds = $phases->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        if ($phaseIds !== [] && EmployeeSeaService::query()->whereIn('crew_assignment_phase_id', $phaseIds)->exists()) {
            $blockers[] = 'unexpected_sea_service';
        }

        $hasTimesheetSegments = CrewTimesheetSegment::withTrashed()
            ->where(function ($query) use ($assignment, $phaseIds): void {
                $query->where('crew_assignment_id', $assignment->id);

                if ($phaseIds !== []) {
                    $query->orWhereIn('crew_assignment_phase_id', $phaseIds);
                }
            })
            ->exists();

        if ($hasTimesheetSegments) {
            $blockers[] = 'unexpected_timesheet_segments';
        }

        if ($assignment->timesheetPreparationLines->isNotEmpty()) {
            $blockers[] = 'unexpected_timesheet_preparation_lines';
        }

        if ($assignment->accommodationStays->isNotEmpty()) {
            $blockers[] = 'unexpected_accommodation_stays';
        }

        $hasPendingCorrection = $assignment->corrections
            ->contains(fn ($correction): bool => $correction->status === CrewMovementCorrectionStatus::Pending);

        if ($hasPendingCorrection) {
            $blockers[] = 'unexpected_pending_movement_correction';
        } elseif ($assignment->corrections->isNotEmpty()) {
            $blockers[] = 'unexpected_movement_corrections';
        }

        if (PayrollWorkAllocation::query()->where('crew_assignment_id', $assignment->id)->exists()) {
            $blockers[] = 'unexpected_payroll_work_allocations';
        }

        return array_values(array_unique($blockers));
    }

    private function retireOne(SoftDeletedPlannedRetirementCandidate $candidate, ?User $actor): SoftDeletedPlannedRetirementCandidate
    {
        return DB::transaction(function () use ($candidate, $actor): SoftDeletedPlannedRetirementCandidate {
            $companyId = $candidate->companyId;
            $actorId = $actor?->id;

            /** @var CrewAssignment $assignment */
            $assignment = CrewAssignment::withTrashed()
                ->where('company_id', $companyId)
                ->whereKey($candidate->assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            $phases = CrewAssignmentPhase::withTrashed()
                ->where('crew_assignment_id', $assignment->id)
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $assignment->setRelation('phases', $phases);
            $assignment->load([
                'timesheetPreparationLines:id,crew_assignment_id',
                'accommodationStays:id,crew_assignment_id',
                'corrections:id,crew_assignment_id,status',
            ]);

            if (! $assignment->trashed() || $assignment->deleted_at === null) {
                throw new \RuntimeException('Assignment is no longer soft-deleted; refusing to retire.');
            }

            if ($assignment->status !== CrewAssignmentStatus::Planned) {
                throw new \RuntimeException('Assignment is no longer Planned; refusing to retire.');
            }

            $originalDeletedAt = $assignment->deleted_at?->toDateTimeString();

            $reclassified = $this->classify($assignment);

            if ($reclassified->migrationStatus === SoftDeletedPlannedRetirementCandidate::STATUS_BLOCKED) {
                throw new \RuntimeException('Preflight blockers appeared before apply: '.implode(', ', $reclassified->blockers));
            }

            /** @var CrewAssignmentPhase|null $p0 */
            $p0 = $phases->first();
            if ($p0 !== null && $p0->status !== CrewPhaseStatus::Cancelled) {
                // Never invent actual phase timestamps — Planned P0 never started.
                // When Artisan runs without an actor, preserve historical completed_by.
                $phaseUpdates = [
                    'status' => CrewPhaseStatus::Cancelled,
                    'actual_start_at' => null,
                    'actual_end_at' => null,
                ];

                if ($actorId !== null) {
                    $phaseUpdates['completed_by'] = $actorId;
                }

                $p0->update($phaseUpdates);
            }

            $closedAt = $assignment->closed_at ?? $assignment->deleted_at;

            // Preserve deleted_at exactly. Never restore. No Planning conversion.
            // When Artisan runs without an actor, preserve historical updated_by.
            $assignmentUpdates = [
                'status' => CrewAssignmentStatus::Cancelled,
                'closed_at' => $closedAt,
            ];

            if ($actorId !== null) {
                $assignmentUpdates['updated_by'] = $actorId;
            }

            $assignment->update($assignmentUpdates);

            $fresh = CrewAssignment::withTrashed()->whereKey($assignment->id)->firstOrFail();

            if (! $fresh->trashed()) {
                throw new \RuntimeException('Retirement unexpectedly restored the assignment.');
            }

            if ($fresh->deleted_at?->toDateTimeString() !== $originalDeletedAt) {
                throw new \RuntimeException('Retirement unexpectedly changed deleted_at.');
            }

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $timestamp = CarbonImmutable::now($timezone)->toIso8601String();

            activity()
                ->performedOn($fresh)
                ->causedBy($actor)
                ->withProperties([
                    'crew_assignment_id' => $fresh->id,
                    'assignment_no' => $fresh->assignment_no,
                    'company_id' => $companyId,
                    'old_status' => CrewAssignmentStatus::Planned->value,
                    'new_status' => CrewAssignmentStatus::Cancelled->value,
                    'original_deleted_at' => $originalDeletedAt,
                    'maintenance_version' => self::VERSION,
                    'timestamp' => $timestamp,
                ])
                ->log(self::ACTIVITY);

            return new SoftDeletedPlannedRetirementCandidate(
                assignmentId: $candidate->assignmentId,
                assignmentNo: $candidate->assignmentNo,
                companyId: $candidate->companyId,
                employeeId: $candidate->employeeId,
                employeeName: $candidate->employeeName,
                vesselId: $candidate->vesselId,
                vesselName: $candidate->vesselName,
                positionId: $candidate->positionId,
                positionName: $candidate->positionName,
                deletedAt: $originalDeletedAt,
                closedAt: $fresh->closed_at?->toDateTimeString(),
                migrationStatus: SoftDeletedPlannedRetirementCandidate::STATUS_RETIRED,
                disposition: SoftDeletedPlannedRetirementCandidate::DISPOSITION_RETIRE_TO_CANCELLED,
                blockers: [],
            );
        });
    }

    /**
     * @param  list<SoftDeletedPlannedRetirementCandidate>  $candidates
     * @param  list<int>  $companyIds
     */
    private function makeReport(
        array $candidates,
        bool $applied,
        bool $abortedDueToBlockers,
        array $companyIds,
        ?int $assignmentId = null,
        int $retiredCount = 0,
    ): SoftDeletedPlannedRetirementReport {
        $remainingQuery = CrewAssignment::withTrashed()
            ->whereNotNull('deleted_at')
            ->where('status', CrewAssignmentStatus::Planned)
            ->whereIn('company_id', $companyIds);

        if ($assignmentId !== null) {
            $remainingQuery->whereKey($assignmentId);
        }

        return new SoftDeletedPlannedRetirementReport(
            candidates: $candidates,
            companyIds: $companyIds,
            applied: $applied,
            abortedDueToBlockers: $abortedDueToBlockers,
            retiredCount: $retiredCount,
            remainingSoftDeletedPlannedCount: (int) $remainingQuery->count(),
        );
    }
}
