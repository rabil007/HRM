<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertType;
use App\Enums\CrewProjectedManningStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Enums\CrewScheduledMovementStatus;
use App\Enums\CrewTourStatus;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Support\CrewMovements\CrewReliefStatusQuery;
use App\Support\CrewMovements\CrewTourStatusQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;

/**
 * Detects current Crew operational alert subjects from authoritative domain queries.
 *
 * Does not persist alerts and does not read the Daily Operations Action Required list.
 *
 * @phpstan-type DetectedAlert array{
 *     type: CrewOperationalAlertType,
 *     severity: CrewOperationalAlertSeverity,
 *     dedupe_key: string,
 *     title: string,
 *     message: string,
 *     context: array<string, mixed>
 * }
 */
final class DetectCrewOperationalAlerts
{
    public function __construct(
        private readonly CrewTourStatusQuery $tourStatusQuery = new CrewTourStatusQuery,
        private readonly CrewReliefStatusQuery $reliefStatusQuery = new CrewReliefStatusQuery,
        private readonly CrewProjectedManningQuery $projectedManningQuery = new CrewProjectedManningQuery,
    ) {}

    /**
     * @param  list<CrewOperationalAlertType>  $enabledTypes
     * @return list<DetectedAlert>
     */
    public function forCompany(int $companyId, array $enabledTypes): array
    {
        if ($enabledTypes === []) {
            return [];
        }

        $enabled = collect($enabledTypes)->keyBy(fn (CrewOperationalAlertType $type): string => $type->value);
        $detected = [];

        if ($enabled->has(CrewOperationalAlertType::SignoffOverdue->value)) {
            $detected = array_merge($detected, $this->signoffOverdue($companyId));
        }

        if ($enabled->has(CrewOperationalAlertType::SignoffNoRelief->value)
            || $enabled->has(CrewOperationalAlertType::ReliefNotReady->value)) {
            $reliefAlerts = $this->reliefAlerts(
                $companyId,
                $enabled->has(CrewOperationalAlertType::SignoffNoRelief->value),
                $enabled->has(CrewOperationalAlertType::ReliefNotReady->value),
            );
            $detected = array_merge($detected, $reliefAlerts);
        }

        if ($enabled->has(CrewOperationalAlertType::CurrentManningGap->value)) {
            $detected = array_merge($detected, $this->currentManningGaps($companyId));
        }

        if ($enabled->has(CrewOperationalAlertType::ProjectedManningGap->value)) {
            $detected = array_merge($detected, $this->projectedManningGaps($companyId));
        }

        if ($enabled->has(CrewOperationalAlertType::ScheduledMovementNeedsAttention->value)) {
            $detected = array_merge($detected, $this->scheduledMovementsNeedingAttention($companyId));
        }

        return $detected;
    }

    /**
     * @return list<DetectedAlert>
     */
    private function signoffOverdue(int $companyId): array
    {
        $query = CrewAssignment::query()->where('company_id', $companyId);
        $this->tourStatusQuery->applyFilter($query, CrewTourStatus::Overdue->value, $companyId);

        $assignments = $query
            ->with(['employee:id,name', 'vessel:id,name', 'position:id,title'])
            ->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'position_id', 'planned_signoff_at']);

        $alerts = [];

        foreach ($assignments as $assignment) {
            $employeeName = $assignment->employee?->name ?? 'Crew member';
            $vesselName = $assignment->vessel?->name ?? 'Unassigned vessel';
            $positionName = $assignment->position?->title ?? 'Unassigned position';

            $alerts[] = [
                'type' => CrewOperationalAlertType::SignoffOverdue,
                'severity' => CrewOperationalAlertSeverity::Critical,
                'dedupe_key' => 'signoff_overdue:assignment:'.$assignment->id,
                'title' => 'Sign-off overdue',
                'message' => sprintf(
                    '%s · %s on %s is past planned sign-off%s.',
                    $employeeName,
                    $positionName,
                    $vesselName,
                    $assignment->planned_signoff_at !== null
                        ? ' ('.$assignment->planned_signoff_at->toDateString().')'
                        : '',
                ),
                'context' => [
                    'assignment_id' => (int) $assignment->id,
                    'assignment_no' => $assignment->assignment_no,
                    'employee_id' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
                    'vessel_id' => $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null,
                    'position_id' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
                    'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
                ],
            ];
        }

        return $alerts;
    }

    /**
     * @return list<DetectedAlert>
     */
    private function reliefAlerts(int $companyId, bool $noRelief, bool $notReady): array
    {
        $resolved = $this->reliefStatusQuery->resolveActiveOnVessel($companyId);
        $assignmentIds = $resolved->keys()->all();

        $assignments = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $assignmentIds === [] ? [0] : $assignmentIds)
            ->with(['employee:id,name', 'vessel:id,name', 'position:id,title'])
            ->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'position_id', 'planned_signoff_at'])
            ->keyBy('id');

        $alerts = [];

        foreach ($resolved as $assignmentId => $result) {
            $assignment = $assignments->get($assignmentId);

            if ($assignment === null) {
                continue;
            }

            $daysUntil = $result->daysUntilSignoff;
            $employeeName = $assignment->employee?->name ?? 'Crew member';
            $vesselName = $assignment->vessel?->name ?? 'Unassigned vessel';
            $positionName = $assignment->position?->title ?? 'Unassigned position';
            $baseContext = [
                'assignment_id' => (int) $assignment->id,
                'assignment_no' => $assignment->assignment_no,
                'employee_id' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
                'vessel_id' => $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null,
                'position_id' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
                'planned_signoff_at' => $assignment->planned_signoff_at?->toDateString(),
                'days_until_signoff' => $daysUntil,
                'relief_status' => $result->status->value,
                'relief_risk' => $result->risk->value,
            ];

            $within14NoRelief = $daysUntil !== null
                && $daysUntil >= 0
                && $daysUntil <= 14
                && $result->status === CrewReliefStatus::NoRelief;

            if ($noRelief && $within14NoRelief) {
                $alerts[] = [
                    'type' => CrewOperationalAlertType::SignoffNoRelief,
                    'severity' => $this->signoffWindowSeverity($daysUntil),
                    'dedupe_key' => 'signoff_no_relief:assignment:'.$assignment->id,
                    'title' => 'Sign-off approaching — no relief',
                    'message' => sprintf(
                        '%s · %s on %s signs off within 14 days with no relief planned.',
                        $employeeName,
                        $positionName,
                        $vesselName,
                    ),
                    'context' => $baseContext,
                ];
            }

            $imminentNotReady = $daysUntil !== null
                && $daysUntil >= 0
                && $daysUntil <= 7
                && in_array($result->status, CrewReliefStatus::notReady(), true)
                && $result->status !== CrewReliefStatus::NoRelief;

            if ($notReady && $imminentNotReady) {
                $alerts[] = [
                    'type' => CrewOperationalAlertType::ReliefNotReady,
                    'severity' => $this->reliefNotReadySeverity($daysUntil, $result->risk),
                    'dedupe_key' => 'relief_not_ready:assignment:'.$assignment->id,
                    'title' => 'Relief not ready',
                    'message' => sprintf(
                        '%s · %s on %s signs off within 7 days and relief is not ready (%s).',
                        $employeeName,
                        $positionName,
                        $vesselName,
                        $result->status->label(),
                    ),
                    'context' => $baseContext,
                ];
            }
        }

        return $alerts;
    }

    private function signoffWindowSeverity(?int $daysUntil): CrewOperationalAlertSeverity
    {
        if ($daysUntil === null || $daysUntil <= 7) {
            return CrewOperationalAlertSeverity::Critical;
        }

        return CrewOperationalAlertSeverity::Warning;
    }

    private function reliefNotReadySeverity(?int $daysUntil, CrewReliefRisk $risk): CrewOperationalAlertSeverity
    {
        if ($daysUntil !== null && $daysUntil <= 0) {
            return CrewOperationalAlertSeverity::Critical;
        }

        if ($risk === CrewReliefRisk::Critical || ($daysUntil !== null && $daysUntil <= 3)) {
            return CrewOperationalAlertSeverity::Critical;
        }

        return CrewOperationalAlertSeverity::Warning;
    }

    /**
     * @return list<DetectedAlert>
     */
    private function currentManningGaps(int $companyId): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $gaps = CrewOperationsManningGapQuery::forCompany($companyId, $today);
        $alerts = [];

        foreach ($gaps['items'] as $gap) {
            $alerts[] = [
                'type' => CrewOperationalAlertType::CurrentManningGap,
                'severity' => CrewOperationalAlertSeverity::Critical,
                'dedupe_key' => sprintf(
                    'current_manning_gap:vessel:%d:position:%d',
                    $gap['vessel_id'],
                    $gap['position_id'],
                ),
                'title' => 'Current manning gap',
                'message' => sprintf(
                    '%s · %s is short %d now (%d of %d onboard).',
                    $gap['vessel_name'],
                    $gap['position_name'],
                    $gap['gap'],
                    $gap['actual_count'],
                    $gap['required_count'],
                ),
                'context' => [
                    'vessel_id' => (int) $gap['vessel_id'],
                    'vessel_name' => (string) $gap['vessel_name'],
                    'position_id' => (int) $gap['position_id'],
                    'position_name' => (string) $gap['position_name'],
                    'gap' => (int) $gap['gap'],
                    'actual_count' => (int) $gap['actual_count'],
                    'required_count' => (int) $gap['required_count'],
                ],
            ];
        }

        return $alerts;
    }

    /**
     * @return list<DetectedAlert>
     */
    private function projectedManningGaps(int $companyId): array
    {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $from = CarbonImmutable::now($timezone)->toDateString();
        $to = CarbonImmutable::parse($from, $timezone)->addDays(30)->toDateString();
        $projection = $this->projectedManningQuery->forCompany($companyId, $from, $to);
        $alerts = [];

        foreach ($projection['items'] as $item) {
            if ($item['status'] !== CrewProjectedManningStatus::FutureGap->value) {
                continue;
            }

            if ((int) $item['maximum_gap'] <= 0) {
                continue;
            }

            $alerts[] = [
                'type' => CrewOperationalAlertType::ProjectedManningGap,
                'severity' => CrewOperationalAlertSeverity::Warning,
                'dedupe_key' => sprintf(
                    'projected_manning_gap:vessel:%d:position:%d',
                    $item['vessel_id'],
                    $item['position_id'],
                ),
                'title' => 'Projected manning gap',
                'message' => sprintf(
                    '%s · %s has a projected future gap (max short %d)%s.',
                    $item['vessel_name'],
                    $item['position_name'],
                    $item['maximum_gap'],
                    is_string($item['next_gap_date'] ?? null) && $item['next_gap_date'] !== ''
                        ? ' from '.$item['next_gap_date']
                        : '',
                ),
                'context' => [
                    'vessel_id' => (int) $item['vessel_id'],
                    'vessel_name' => (string) $item['vessel_name'],
                    'position_id' => (int) $item['position_id'],
                    'position_name' => (string) $item['position_name'],
                    'maximum_gap' => (int) $item['maximum_gap'],
                    'next_gap_date' => $item['next_gap_date'] ?? null,
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        }

        return $alerts;
    }

    /**
     * @return list<DetectedAlert>
     */
    private function scheduledMovementsNeedingAttention(int $companyId): array
    {
        $schedules = CrewScheduledMovement::query()
            ->where('company_id', $companyId)
            ->where('status', CrewScheduledMovementStatus::NeedsAttention)
            ->with(['assignment:id,assignment_no,vessel_id', 'assignment.vessel:id,name', 'employee:id,name,employee_no'])
            ->orderBy('id')
            ->get([
                'id',
                'crew_assignment_id',
                'employee_id',
                'movement_action',
                'last_error_code',
                'last_error_message',
                'scheduled_at',
                'scheduled_timezone',
            ]);

        $alerts = [];

        foreach ($schedules as $schedule) {
            $actionLabel = $schedule->movement_action->label();
            $employeeName = $schedule->employee?->name ?? 'Crew member';
            $errorCode = $schedule->last_error_code ?? 'needs_attention';
            $safeMessage = filled($schedule->last_error_message)
                ? mb_substr(trim((string) $schedule->last_error_message), 0, 240)
                : 'Automatic execution needs operator review.';

            $alerts[] = [
                'type' => CrewOperationalAlertType::ScheduledMovementNeedsAttention,
                'severity' => CrewOperationalAlertSeverity::Warning,
                'dedupe_key' => 'scheduled_movement_needs_attention:schedule:'.$schedule->id,
                'title' => 'Scheduled movement needs attention',
                'message' => sprintf(
                    '%s — %s requires review (%s). %s',
                    $employeeName,
                    $actionLabel,
                    $errorCode,
                    $safeMessage,
                ),
                'context' => [
                    'schedule_id' => (int) $schedule->id,
                    'assignment_id' => (int) $schedule->crew_assignment_id,
                    'employee_id' => (int) $schedule->employee_id,
                    'movement_action' => $schedule->movement_action->value,
                    'movement_action_label' => $actionLabel,
                    'last_error_code' => $errorCode,
                    'vessel_name' => $schedule->assignment?->vessel?->name,
                    'assignment_no' => $schedule->assignment?->assignment_no,
                ],
            ];
        }

        return $alerts;
    }
}
