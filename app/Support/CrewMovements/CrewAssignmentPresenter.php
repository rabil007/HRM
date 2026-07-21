<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use Carbon\CarbonInterface;

class CrewAssignmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(CrewAssignment $assignment): array
    {
        $current = $assignment->currentPhase;
        $timezone = self::companyTimezone($assignment);

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'employee' => $assignment->employee ? [
                'id' => $assignment->employee->id,
                'name' => $assignment->employee->name,
                'employee_no' => $assignment->employee->employee_no,
            ] : null,
            'rank' => $assignment->rank ? [
                'id' => $assignment->rank->id,
                'name' => $assignment->rank->name,
            ] : null,
            'vessel' => $assignment->vessel ? [
                'id' => $assignment->vessel->id,
                'name' => $assignment->vessel->name,
            ] : null,
            'client' => $assignment->client ? [
                'id' => $assignment->client->id,
                'name' => $assignment->client->name,
            ] : null,
            'company_visa_type' => $assignment->companyVisaType ? [
                'id' => $assignment->companyVisaType->id,
                'name' => $assignment->companyVisaType->name,
            ] : null,
            'current_phase' => $current ? [
                'id' => $current->id,
                'code' => $current->phase_code->value,
                'label' => $current->phase_code->label(),
                'status' => $current->status->value,
                'started_at' => self::formatDateTime($current->actual_start_at, $timezone),
            ] : null,
            'days_in_phase' => self::wholeDaysSince($current?->actual_start_at, $timezone),
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'actual_join_at' => self::latestOnVesselPhase($assignment)?->actual_start_at?->toDateString(),
            'actual_disembarkation_at' => self::latestOnVesselPhase($assignment)?->actual_end_at?->toDateString(),
            'created_at' => $assignment->created_at?->toDateString(),
            'company_timezone' => $timezone,
            'warnings' => property_exists($assignment, 'attention_warnings')
                ? $assignment->attention_warnings
                : CrewMovementAttentionQuery::forAssignment($assignment),
            'available_actions' => CrewMovementAvailableActions::for($assignment),
            'movement_context' => self::movementContext($assignment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(CrewAssignment $assignment): array
    {
        $current = $assignment->currentPhase;
        $timezone = self::companyTimezone($assignment);
        $onVesselPhase = self::latestOnVesselPhase($assignment);
        $trainingPhase = self::latestPhase($assignment, CrewPhaseCode::Training);

        $phaseTimeline = $assignment->phases
            ->map(function ($phase) {
                $hasPending = $phase->relationLoaded('pendingCorrections')
                    ? $phase->pendingCorrections->isNotEmpty()
                    : false;
                $hasApproved = $phase->relationLoaded('corrections')
                    ? $phase->corrections->where('status', CrewMovementCorrectionStatus::Approved)->isNotEmpty()
                    : false;

                return [
                    'id' => $phase->id,
                    'sequence' => $phase->sequence,
                    'phase_code' => $phase->phase_code->value,
                    'phase_label' => $phase->phase_code->label(),
                    'status' => $phase->status->value,
                    'status_label' => $phase->status->label(),
                    'planned_start_at' => $phase->planned_start_at?->toDateString(),
                    'planned_end_at' => $phase->planned_end_at?->toDateString(),
                    'actual_start_at' => $phase->actual_start_at?->toDateString(),
                    'actual_end_at' => $phase->actual_end_at?->toDateString(),
                    'details' => $phase->details,
                    'remarks' => $phase->remarks,
                    'has_pending_correction' => $hasPending,
                    'has_approved_correction' => $hasApproved,
                ];
            })
            ->sortBy('sequence')
            ->values()
            ->all();

        return [
            'id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'employee' => $assignment->employee ? [
                'id' => $assignment->employee->id,
                'name' => $assignment->employee->name,
                'employee_no' => $assignment->employee->employee_no,
            ] : null,
            'rank' => $assignment->rank ? [
                'id' => $assignment->rank->id,
                'name' => $assignment->rank->name,
            ] : null,
            'vessel' => $assignment->vessel ? [
                'id' => $assignment->vessel->id,
                'name' => $assignment->vessel->name,
            ] : null,
            'client' => $assignment->client ? [
                'id' => $assignment->client->id,
                'name' => $assignment->client->name,
            ] : null,
            'company_visa_type' => $assignment->companyVisaType ? [
                'id' => $assignment->companyVisaType->id,
                'name' => $assignment->companyVisaType->name,
            ] : null,
            'current_phase' => $current ? [
                'id' => $current->id,
                'code' => $current->phase_code->value,
                'label' => $current->phase_code->label(),
                'status' => $current->status->value,
                'status_label' => $current->status->label(),
                'started_at' => self::formatDateTime($current->actual_start_at, $timezone),
            ] : null,
            'days_in_phase' => self::wholeDaysSince($current?->actual_start_at, $timezone),
            'days_onboard' => self::wholeDaysBetween(
                $onVesselPhase?->actual_start_at,
                $onVesselPhase?->actual_end_at ?? now($timezone),
                $timezone,
            ),
            'days_in_training' => self::wholeDaysBetween(
                $trainingPhase?->actual_start_at,
                $trainingPhase?->actual_end_at ?? (
                    $trainingPhase?->status === CrewPhaseStatus::Active ? now($timezone) : null
                ),
                $timezone,
            ),
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'actual_join_at' => $onVesselPhase?->actual_start_at?->toDateString(),
            'actual_disembarkation_at' => $onVesselPhase?->actual_end_at?->toDateString(),
            'started_at' => $assignment->started_at?->toDateString(),
            'closed_at' => $assignment->closed_at?->toDateString(),
            'source' => $assignment->source,
            'remarks' => $assignment->remarks,
            'created_at' => $assignment->created_at?->toDateString(),
            'updated_at' => $assignment->updated_at?->toDateString(),
            'company_timezone' => $timezone,
            'phase_timeline' => $phaseTimeline,
            'warnings' => CrewMovementAttentionQuery::forAssignment($assignment),
            'available_actions' => CrewMovementAvailableActions::for($assignment),
            'planning_assignment_id' => $assignment->planningAssignment?->id,
            'movement_context' => self::movementContext($assignment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function movementContext(CrewAssignment $assignment): array
    {
        $timezone = self::companyTimezone($assignment);
        $current = $assignment->currentPhase;
        $onVesselPhase = self::latestOnVesselPhase($assignment);
        $trainingPhase = self::latestPhase($assignment, CrewPhaseCode::Training);

        return [
            'assignment_id' => $assignment->id,
            'assignment_no' => $assignment->assignment_no,
            'employee_id' => $assignment->employee_id,
            'employee_name' => $assignment->employee?->name,
            'employee_no' => $assignment->employee?->employee_no,
            'current_phase_code' => $current?->phase_code->value,
            'current_phase_label' => $current?->phase_code->label(),
            'current_phase_started_at' => self::formatDateTime($current?->actual_start_at, $timezone),
            'days_in_phase' => self::wholeDaysSince($current?->actual_start_at, $timezone),
            'days_onboard' => self::wholeDaysBetween(
                $onVesselPhase?->actual_start_at,
                $onVesselPhase?->actual_end_at ?? now($timezone),
                $timezone,
            ),
            'days_in_training' => self::wholeDaysBetween(
                $trainingPhase?->actual_start_at,
                $trainingPhase?->actual_end_at ?? (
                    $trainingPhase?->status === CrewPhaseStatus::Active ? now($timezone) : null
                ),
                $timezone,
            ),
            'vessel_id' => $assignment->vessel_id,
            'vessel_name' => $assignment->vessel?->name,
            'rank_id' => $assignment->rank_id,
            'rank_name' => $assignment->rank?->name,
            'client_id' => $assignment->client_id,
            'client_name' => $assignment->client?->name,
            'visa_type_id' => $assignment->company_visa_type_id,
            'visa_type_name' => $assignment->companyVisaType?->name,
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'actual_join_at' => $onVesselPhase?->actual_start_at?->toDateString(),
            'actual_disembarkation_at' => $onVesselPhase?->actual_end_at?->toDateString(),
            'training_provider' => is_array($trainingPhase?->details) ? ($trainingPhase->details['provider'] ?? null) : null,
            'training_course' => is_array($trainingPhase?->details) ? ($trainingPhase->details['course'] ?? null) : null,
            'training_started_at' => self::formatDateTime($trainingPhase?->actual_start_at, $timezone),
            'training_expected_completion_at' => $trainingPhase?->planned_end_at?->toDateString(),
            'company_timezone' => $timezone,
        ];
    }

    private static function companyTimezone(CrewAssignment $assignment): string
    {
        return (string) ($assignment->company?->timezone ?? config('app.timezone', 'UTC'));
    }

    private static function latestOnVesselPhase(CrewAssignment $assignment): ?CrewAssignmentPhase
    {
        return self::latestPhase($assignment, CrewPhaseCode::OnVessel);
    }

    private static function latestPhase(CrewAssignment $assignment, CrewPhaseCode $code): ?CrewAssignmentPhase
    {
        return $assignment->phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === $code)
            ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->first();
    }

    private static function wholeDaysSince(?CarbonInterface $start, string $timezone): ?int
    {
        if ($start === null) {
            return null;
        }

        return self::wholeDaysBetween($start, now($timezone), $timezone);
    }

    private static function wholeDaysBetween(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        string $timezone,
    ): ?int {
        if ($start === null || $end === null) {
            return null;
        }

        $from = $start->copy()->timezone($timezone)->startOfDay();
        $to = $end->copy()->timezone($timezone)->startOfDay();

        return (int) $from->diffInDays($to);
    }

    private static function formatDateTime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->format('Y-m-d H:i:s');
    }
}
