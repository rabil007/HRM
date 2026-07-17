<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SyncPlanningAssignmentFromCrewAssignment
{
    public function sync(CrewAssignment $assignment): ?CrewPlanningAssignment
    {
        return DB::transaction(function () use ($assignment): ?CrewPlanningAssignment {
            $assignment = CrewAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->with(['phases', 'employee', 'company'])
                ->firstOrFail();

            $linked = CrewPlanningAssignment::query()
                ->withTrashed()
                ->where('crew_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->first();

            if ($linked !== null && (int) $linked->company_id !== (int) $assignment->company_id) {
                return null;
            }

            $latestP4 = $this->latestOnVesselPhase($assignment);
            $hasCompletedP4 = $latestP4 !== null
                && $latestP4->status === CrewPhaseStatus::Completed
                && $latestP4->actual_start_at !== null
                && $latestP4->actual_end_at !== null;

            if ($assignment->status === CrewAssignmentStatus::Cancelled) {
                if ($hasCompletedP4) {
                    return $this->upsert($assignment, $linked, $latestP4, requireLeave: true);
                }

                if ($linked !== null && ! $linked->trashed()) {
                    $linked->delete();
                }

                return null;
            }

            if ($latestP4 !== null && $latestP4->status === CrewPhaseStatus::Active) {
                if ($latestP4->actual_start_at === null
                    || $assignment->vessel_id === null
                    || $this->resolvedRankId($assignment) === null
                    || $assignment->employee_id === null) {
                    return $linked;
                }

                return $this->upsert($assignment, $linked, $latestP4, requireLeave: false);
            }

            if ($hasCompletedP4) {
                return $this->upsert($assignment, $linked, $latestP4, requireLeave: true);
            }

            if ($assignment->planned_join_at === null
                || $assignment->planned_signoff_at === null
                || $assignment->vessel_id === null
                || $this->resolvedRankId($assignment) === null
                || $assignment->employee_id === null) {
                return $linked;
            }

            return $this->upsert($assignment, $linked, null, requireLeave: true);
        });
    }

    private function upsert(
        CrewAssignment $assignment,
        ?CrewPlanningAssignment $linked,
        ?CrewAssignmentPhase $onVesselPhase,
        bool $requireLeave,
    ): ?CrewPlanningAssignment {
        $joinDate = $this->resolveJoinDate($assignment, $onVesselPhase);
        $leaveDate = $this->resolveLeaveDate($assignment, $onVesselPhase);
        $rankId = $this->resolvedRankId($assignment);

        if ($joinDate === null
            || $assignment->vessel_id === null
            || $rankId === null
            || $assignment->employee_id === null) {
            return $linked;
        }

        if ($requireLeave && $leaveDate === null) {
            return $linked;
        }

        $attributes = [
            'company_id' => $assignment->company_id,
            'employee_id' => $assignment->employee_id,
            'vessel_id' => $assignment->vessel_id,
            'rank_id' => $rankId,
            'crew_assignment_id' => $assignment->id,
            'planned_join_date' => $joinDate,
            'planned_leave_date' => $leaveDate,
        ];

        if ($linked !== null) {
            if ($linked->trashed()) {
                $linked->restore();
            }

            $linked->update($attributes);

            return $linked->fresh();
        }

        return CrewPlanningAssignment::query()->create($attributes);
    }

    private function latestOnVesselPhase(CrewAssignment $assignment): ?CrewAssignmentPhase
    {
        return $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::OnVessel)
            ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->first();
    }

    private function resolveJoinDate(CrewAssignment $assignment, ?CrewAssignmentPhase $onVesselPhase): ?string
    {
        if ($onVesselPhase?->actual_start_at !== null) {
            return $this->toCompanyDate($onVesselPhase->actual_start_at, (int) $assignment->company_id);
        }

        if ($assignment->planned_join_at !== null) {
            return $this->toCompanyDate($assignment->planned_join_at, (int) $assignment->company_id);
        }

        return null;
    }

    private function resolveLeaveDate(CrewAssignment $assignment, ?CrewAssignmentPhase $onVesselPhase): ?string
    {
        if ($onVesselPhase?->actual_end_at !== null) {
            return $this->toCompanyDate($onVesselPhase->actual_end_at, (int) $assignment->company_id);
        }

        if ($assignment->planned_signoff_at !== null) {
            return $this->toCompanyDate($assignment->planned_signoff_at, (int) $assignment->company_id);
        }

        if ($onVesselPhase?->planned_end_at !== null) {
            return $this->toCompanyDate($onVesselPhase->planned_end_at, (int) $assignment->company_id);
        }

        return null;
    }

    private function resolvedRankId(CrewAssignment $assignment): ?int
    {
        $rankId = $assignment->rank_id ?? $assignment->employee?->rank_id;

        return $rankId !== null ? (int) $rankId : null;
    }

    private function toCompanyDate(CarbonInterface $value, int $companyId): string
    {
        $timezone = (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));

        return $value->copy()->timezone($timezone)->toDateString();
    }
}
