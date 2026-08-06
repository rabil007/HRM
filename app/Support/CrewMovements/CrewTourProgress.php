<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTourStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;

/**
 * Operational Tour of Duty progress for active (or completed) P4 assignments.
 *
 * @phpstan-type TourProgressArray array{
 *     tour_of_duty_days: int|null,
 *     tour_of_duty_source: string|null,
 *     tour_of_duty_source_label: string|null,
 *     planned_signoff_source: string|null,
 *     planned_signoff_source_label: string|null,
 *     days_onboard: int|null,
 *     current_duty_day: int|null,
 *     remaining_tour_days: int|null,
 *     tour_progress_percent: float|null,
 *     tour_progress_display_percent: float|null,
 *     tour_status: string|null,
 *     tour_status_label: string|null,
 *     tour_status_severity: string|null
 * }
 */
final class CrewTourProgress
{
    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
    ) {}

    /**
     * @return TourProgressArray
     */
    public function forAssignment(
        CrewAssignment $assignment,
        ?CarbonInterface $asOf = null,
        ?string $timezone = null,
    ): array {
        $timezone ??= $this->resolveTimezone($assignment);
        $onVessel = $this->latestOnVesselPhase($assignment);

        $tourDays = $assignment->tour_of_duty_days !== null
            ? (int) $assignment->tour_of_duty_days
            : null;
        $tourSource = $assignment->tour_of_duty_source;
        $signoffSource = $assignment->planned_signoff_source;
        $plannedSignoff = $assignment->planned_signoff_at;

        $empty = [
            'tour_of_duty_days' => $tourDays,
            'tour_of_duty_source' => $tourSource?->value,
            'tour_of_duty_source_label' => $tourSource?->label(),
            'planned_signoff_source' => $signoffSource?->value,
            'planned_signoff_source_label' => $signoffSource?->label(),
            'days_onboard' => null,
            'current_duty_day' => null,
            'remaining_tour_days' => null,
            'tour_progress_percent' => null,
            'tour_progress_display_percent' => null,
            'tour_status' => null,
            'tour_status_label' => null,
            'tour_status_severity' => null,
        ];

        if ($onVessel?->actual_start_at === null) {
            return $empty;
        }

        $isCompletedP4 = $onVessel->status === CrewPhaseStatus::Completed
            && $onVessel->actual_end_at !== null;

        // Active P4 uses "now"; completed P4 freezes progress at actual disembarkation.
        $progressAsOf = $isCompletedP4
            ? $onVessel->actual_end_at
            : ($asOf ?? now($timezone));

        $daysOnboard = $this->calculator->daysOnboard(
            $onVessel->actual_start_at,
            $timezone,
            $progressAsOf,
        );

        $remaining = $plannedSignoff !== null
            ? $this->calculator->remainingTourDays($plannedSignoff, $timezone, $progressAsOf)
            : null;

        $rawPercent = $tourDays !== null
            ? $this->calculator->tourProgressPercent($daysOnboard, $tourDays)
            : null;

        // Operational tour status / alerts only apply to active On Vessel.
        $status = null;

        if ($onVessel->status === CrewPhaseStatus::Active) {
            $status = $this->calculator->resolveStatus(
                $tourDays,
                $plannedSignoff,
                $timezone,
                $progressAsOf,
            );
        }

        return [
            'tour_of_duty_days' => $tourDays,
            'tour_of_duty_source' => $tourSource?->value,
            'tour_of_duty_source_label' => $tourSource?->label(),
            'planned_signoff_source' => $signoffSource?->value,
            'planned_signoff_source_label' => $signoffSource?->label(),
            'days_onboard' => $daysOnboard,
            'current_duty_day' => $this->calculator->currentDutyDay($daysOnboard),
            'remaining_tour_days' => $remaining,
            'tour_progress_percent' => $rawPercent,
            'tour_progress_display_percent' => $this->calculator->clampDisplayPercent($rawPercent),
            'tour_status' => $status?->value,
            'tour_status_label' => $status?->label(),
            'tour_status_severity' => $status?->severity(),
        ];
    }

    public function statusForAssignment(
        CrewAssignment $assignment,
        ?CarbonInterface $asOf = null,
        ?string $timezone = null,
    ): ?CrewTourStatus {
        $progress = $this->forAssignment($assignment, $asOf, $timezone);

        return $progress['tour_status'] !== null
            ? CrewTourStatus::tryFrom($progress['tour_status'])
            : null;
    }

    private function resolveTimezone(CrewAssignment $assignment): string
    {
        if ($assignment->relationLoaded('company') && $assignment->company !== null) {
            return CompanyTimezone::forCompany($assignment->company);
        }

        return $this->calculator->timezoneForCompany((int) $assignment->company_id);
    }

    private function latestOnVesselPhase(CrewAssignment $assignment): ?CrewAssignmentPhase
    {
        if (! $assignment->relationLoaded('phases')) {
            $assignment->load('phases');
        }

        return $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::OnVessel)
            ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->first();
    }
}
