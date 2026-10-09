<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewScheduledMovementStatus;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Collection;

final class CrewScheduledMovementPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public function forAssignment(
        CrewAssignment $assignment,
        ?User $viewer = null,
    ): ?array {
        if (! CrewScheduledMovementAccess::canView($viewer)) {
            return null;
        }

        /** @var CrewScheduledMovement|null $active */
        $active = CrewScheduledMovement::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->unresolved()
            ->with(['creator:id,name', 'updater:id,name', 'canceller:id,name'])
            ->latest('scheduled_at')
            ->first();

        $history = CrewScheduledMovement::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->whereIn('status', [
                CrewScheduledMovementStatus::Executed->value,
                CrewScheduledMovementStatus::Cancelled->value,
            ])
            ->with(['creator:id,name', 'updater:id,name', 'canceller:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        if ($active === null && $history->isEmpty()) {
            return [
                'active' => null,
                'history' => [],
                'can_schedule' => CrewScheduledMovementAccess::canSchedule($viewer),
                'can_manage' => CrewScheduledMovementAccess::canManage($viewer),
                'schedulable_actions' => CrewSchedulableMovementActions::values(),
            ];
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);

        return [
            'active' => $active !== null ? $this->card($active, $timezone, $viewer) : null,
            'history' => $history->map(fn (CrewScheduledMovement $row) => $this->card($row, $timezone, $viewer))->values()->all(),
            'can_schedule' => CrewScheduledMovementAccess::canSchedule($viewer),
            'can_manage' => CrewScheduledMovementAccess::canManage($viewer),
            'schedulable_actions' => CrewSchedulableMovementActions::values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(CrewScheduledMovement $schedule, ?string $timezone = null, ?User $viewer = null): array
    {
        $timezone ??= $schedule->scheduled_timezone
            ?: CompanyTimezone::forCompanyId((int) $schedule->company_id);

        $scheduledLocal = $schedule->scheduled_at?->clone()->timezone($timezone);

        return [
            'id' => $schedule->id,
            'crew_assignment_id' => $schedule->crew_assignment_id,
            'employee_id' => $schedule->employee_id,
            'movement_action' => $schedule->movement_action->value,
            'movement_action_label' => $schedule->movement_action->label(),
            'status' => $schedule->status->value,
            'status_label' => $schedule->status->label(),
            'scheduled_at' => $scheduledLocal?->format('Y-m-d H:i:s'),
            'scheduled_at_display' => $scheduledLocal?->format('d M Y H:i'),
            'scheduled_timezone' => $timezone,
            'expected_current_phase_code' => $schedule->expected_current_phase_code,
            'expected_result_phase_code' => $schedule->expected_result_phase_code,
            'expected_result_phase_label' => CrewSchedulableMovementActions::expectedResultPhaseLabel(
                $schedule->movement_action,
                is_array($schedule->action_payload) ? ($schedule->action_payload['next_phase'] ?? null) : null,
            ),
            'action_payload' => $schedule->action_payload ?? [],
            'created_by' => $schedule->creator ? [
                'id' => $schedule->creator->id,
                'name' => $schedule->creator->name,
            ] : null,
            'updated_by' => $schedule->updater ? [
                'id' => $schedule->updater->id,
                'name' => $schedule->updater->name,
            ] : null,
            'cancelled_by' => $schedule->canceller ? [
                'id' => $schedule->canceller->id,
                'name' => $schedule->canceller->name,
            ] : null,
            'executed_at' => $schedule->executed_at?->timezone($timezone)->format('Y-m-d H:i:s'),
            'effective_occurred_at' => $schedule->effective_occurred_at?->timezone($timezone)->format('Y-m-d H:i:s'),
            'cancelled_at' => $schedule->cancelled_at?->timezone($timezone)->format('Y-m-d H:i:s'),
            'execution_attempts' => $schedule->execution_attempts,
            'last_error_code' => $schedule->last_error_code,
            'last_error_message' => $schedule->last_error_message,
            'can_edit' => CrewScheduledMovementAccess::canManage($viewer)
                && in_array($schedule->status, [
                    CrewScheduledMovementStatus::Scheduled,
                    CrewScheduledMovementStatus::NeedsAttention,
                ], true),
            'can_cancel' => CrewScheduledMovementAccess::canManage($viewer)
                && in_array($schedule->status, [
                    CrewScheduledMovementStatus::Scheduled,
                    CrewScheduledMovementStatus::NeedsAttention,
                ], true),
        ];
    }

    /**
     * @param  Collection<int, CrewScheduledMovement>  $rows
     * @return list<array<string, mixed>>
     */
    public function listItems(Collection $rows, string $timezone, ?User $viewer = null): array
    {
        return $rows->map(function (CrewScheduledMovement $row) use ($timezone, $viewer): array {
            $card = $this->card($row, $timezone, $viewer);
            $assignment = $row->relationLoaded('assignment') ? $row->assignment : null;
            $employee = $row->relationLoaded('employee') ? $row->employee : null;

            return [
                ...$card,
                'assignment_no' => $assignment?->assignment_no,
                'employee_name' => $employee?->name,
                'employee_no' => $employee?->employee_no,
                'vessel_name' => $assignment?->vessel?->name,
            ];
        })->values()->all();
    }
}
