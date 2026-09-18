<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPlannedSignoffSource;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\User;
use App\Support\CrewMovements\CrewTourOfDutyCalculator;
use App\Support\CrewMovements\CrewTourOfDutyResolver;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;

/**
 * When an approved correction changes actual P4 start or P4 rank and the Planned Sign-Off
 * was derived from Tour of Duty, recalculate using the resolved tour days.
 */
final class RecalculateTourSignoffAfterP4StartCorrection
{
    public function __construct(
        private readonly CrewTourOfDutyCalculator $calculator = new CrewTourOfDutyCalculator,
        private readonly CrewTourOfDutyResolver $tourResolver = new CrewTourOfDutyResolver,
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

        $rankChanged = array_key_exists('rank_id', $normalizedProposed);
        $startChanged = array_key_exists('actual_start_at', $normalizedProposed);

        if (! $rankChanged && ! $startChanged) {
            return;
        }

        $actualStart = $phase->actual_start_at;
        if (! $actualStart instanceof CarbonInterface) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $previousSignoff = $assignment->planned_signoff_at;
        $previousTourDays = $assignment->tour_of_duty_days;

        if ($rankChanged) {
            $newRankId = (int) $normalizedProposed['rank_id'];
            $tourResult = $this->tourResolver->resolve((int) $assignment->company_id, $newRankId, $actualStart);
            $newTourDays = $tourResult->tourOfDutyDays;

            if ($assignment->planned_signoff_source === CrewPlannedSignoffSource::TourOfDuty) {
                if ($newTourDays === null || $newTourDays <= 0) {
                    return;
                }

                $newSignoff = $this->calculator->suggestedPlannedSignoff(
                    $actualStart,
                    (int) $newTourDays,
                    $timezone,
                );

                $phase->forceFill([
                    'planned_end_at' => $newSignoff,
                ])->save();

                $assignment->forceFill([
                    'tour_of_duty_days' => $newTourDays,
                    'planned_signoff_at' => $newSignoff,
                ])->save();

                activity()
                    ->performedOn($assignment)
                    ->causedBy($actor)
                    ->withProperties([
                        'event' => 'tour_signoff_recalculated',
                        'assignment_id' => $assignment->id,
                        'phase_id' => $phase->id,
                        'rank_id' => $newRankId,
                        'previous_tour_of_duty_days' => $previousTourDays,
                        'tour_of_duty_days' => $newTourDays,
                        'previous_planned_signoff_at' => $previousSignoff?->toDateTimeString(),
                        'new_planned_signoff_at' => $newSignoff->toDateTimeString(),
                        'actual_start_at' => $actualStart->toDateTimeString(),
                    ])
                    ->tap(function ($activity) use ($assignment): void {
                        $activity->company_id = $assignment->company_id;
                    })
                    ->log('Planned Sign-Off recalculated after P4 rank correction');

                return;
            }

            $assignment->forceFill([
                'tour_of_duty_days' => $newTourDays,
            ])->save();

            activity()
                ->performedOn($assignment)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'tour_days_snapshot_updated',
                    'assignment_id' => $assignment->id,
                    'phase_id' => $phase->id,
                    'rank_id' => $newRankId,
                    'previous_tour_of_duty_days' => $previousTourDays,
                    'tour_of_duty_days' => $newTourDays,
                    'planned_signoff_source' => $assignment->planned_signoff_source?->value,
                    'preserved_planned_signoff_at' => $previousSignoff?->toDateTimeString(),
                ])
                ->tap(function ($activity) use ($assignment): void {
                    $activity->company_id = $assignment->company_id;
                })
                ->log('Tour of duty snapshot updated after P4 rank correction while preserving manual sign-off');

            return;
        }

        if ($assignment->planned_signoff_source !== CrewPlannedSignoffSource::TourOfDuty) {
            return;
        }

        $tourDays = $assignment->tour_of_duty_days;
        if ($tourDays === null || $tourDays <= 0) {
            return;
        }

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
            ->tap(function ($activity) use ($assignment): void {
                $activity->company_id = $assignment->company_id;
            })
            ->log('Planned Sign-Off recalculated after P4 start correction');
    }
}
