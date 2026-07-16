<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;

class CrewAssignmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(CrewAssignment $assignment): array
    {
        $current = $assignment->currentPhase;
        $daysInPhase = $current?->actual_start_at?->diffInDays(now($assignment->company->timezone ?? 'UTC'));

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
            'current_phase' => $current ? [
                'code' => $current->phase_code->value,
                'label' => $current->phase_code->label(),
                'status' => $current->status->value,
            ] : null,
            'days_in_phase' => $daysInPhase,
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'created_at' => $assignment->created_at?->toDateString(),
            'warnings' => property_exists($assignment, 'attention_warnings')
                ? $assignment->attention_warnings
                : CrewMovementAttentionQuery::forAssignment($assignment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(CrewAssignment $assignment): array
    {
        $current = $assignment->currentPhase;
        $daysInPhase = $current?->actual_start_at?->diffInDays(now($assignment->company->timezone ?? 'UTC'));

        $phaseTimeline = $assignment->phases
            ->map(fn ($phase) => [
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
            ])
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
                'code' => $current->phase_code->value,
                'label' => $current->phase_code->label(),
                'status' => $current->status->value,
                'status_label' => $current->status->label(),
            ] : null,
            'days_in_phase' => $daysInPhase,
            'planned_join_at' => $assignment->planned_join_at?->toDateString(),
            'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
            'planned_travel_at' => $assignment->planned_travel_at?->toDateString(),
            'started_at' => $assignment->started_at?->toDateString(),
            'closed_at' => $assignment->closed_at?->toDateString(),
            'source' => $assignment->source,
            'remarks' => $assignment->remarks,
            'created_at' => $assignment->created_at?->toDateString(),
            'updated_at' => $assignment->updated_at?->toDateString(),
            'phase_timeline' => $phaseTimeline,
            'warnings' => CrewMovementAttentionQuery::forAssignment($assignment),
            'available_actions' => CrewMovementAvailableActions::for($assignment),
            'planning_assignment_id' => $assignment->planningAssignment?->id,
        ];
    }
}
