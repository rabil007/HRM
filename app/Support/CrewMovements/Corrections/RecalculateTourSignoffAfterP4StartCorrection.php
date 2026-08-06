<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPlannedSignoffSource;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\User;
use App\Support\CrewMovements\CrewTourOfDutyCalculator;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;

/**
 * When an approved correction changes actual P4 start and the Planned Sign-Off
 * was derived from Tour of Duty, recalculate using the snapshotted tour days.
 */
final class RecalculateTourSignoffAfterP4StartCorrection
{
    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedProposed
     */
    public function handle(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        array $normalizedProposed,
        ?User $actor = null,
    ): void {
        if ($phase->phase_code !== CrewPhaseCode::OnVessel) {
            return;
        }

        if (! array_key_exists('actual_start_at', $normalizedProposed)) {
            return;
        }

        if ($assignment->planned_signoff_source !== CrewPlannedSignoffSource::TourOfDuty) {
            return;
        }

        $tourDays = $assignment->tour_of_duty_days;
        if ($tourDays === null || $tourDays <= 0) {
            return;
        }

        $actualStart = $phase->actual_start_at;
        if (! $actualStart instanceof CarbonInterface) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $previousSignoff = $assignment->planned_signoff_at;
        $newSignoff = $this->calculator->suggestedPlannedSignoff(
            $actualStart,
            (int) $tourDays,
            $timezone,
        );

        $phase->forceFill([
            'planned_end_at' => $newSignoff,
        ])->save();

        $assignment->forceFill([
            'planned_signoff_at' => $newSignoff,
        ])->save();

        activity()
            ->performedOn($assignment)
            ->causedBy($actor)
            ->withProperties([
                'event' => 'tour_signoff_recalculated',
                'assignment_id' => $assignment->id,
                'phase_id' => $phase->id,
                'tour_of_duty_days' => $tourDays,
                'previous_planned_signoff_at' => $previousSignoff?->toDateTimeString(),
                'new_planned_signoff_at' => $newSignoff->toDateTimeString(),
                'actual_start_at' => $actualStart->toDateTimeString(),
            ])
            ->log('Planned Sign-Off recalculated after P4 start correction');
    }
}
