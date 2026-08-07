<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertSeverity;
use App\Enums\CrewOperationalAlertType;
use App\Enums\CrewProjectedManningStatus;
use App\Enums\CrewReliefStatus;
use App\Enums\CrewTourStatus;
use App\Models\CrewAssignment;
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
            ->with(['employee:id,name', 'vessel:id,name', 'rank:id,name'])
            ->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'rank_id', 'planned_signoff_at']);

        $alerts = [];

        foreach ($assignments as $assignment) {
            $employeeName = $assignment->employee?->name ?? 'Crew member';
            $vesselName = $assignment->vessel?->name ?? 'Unassigned vessel';
            $rankName = $assignment->rank?->name ?? 'Unassigned rank';

            $alerts[] = [
                'type' => CrewOperationalAlertType::SignoffOverdue,
                'severity' => CrewOperationalAlertSeverity::Critical,
                'dedupe_key' => 'signoff_overdue:assignment:'.$assignment->id,
                'title' => 'Sign-off overdue',
                'message' => sprintf(
                    '%s · %s on %s is past planned sign-off%s.',
                    $employeeName,
                    $rankName,
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
                    'rank_id' => $assignment->rank_id !== null ? (int) $assignment->rank_id : null,
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
            ->with(['employee:id,name', 'vessel:id,name', 'rank:id,name'])
            ->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'rank_id', 'planned_signoff_at'])
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
            $rankName = $assignment->rank?->name ?? 'Unassigned rank';
            $baseContext = [
                'assignment_id' => (int) $assignment->id,
                'assignment_no' => $assignment->assignment_no,
                'employee_id' => $assignment->employee_id !== null ? (int) $assignment->employee_id : null,
                'vessel_id' => $assignment->vessel_id !== null ? (int) $assignment->vessel_id : null,
                'rank_id' => $assignment->rank_id !== null ? (int) $assignment->rank_id : null,
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
                    'severity' => CrewOperationalAlertSeverity::Critical,
                    'dedupe_key' => 'signoff_no_relief:assignment:'.$assignment->id,
                    'title' => 'Sign-off approaching — no relief',
                    'message' => sprintf(
                        '%s · %s on %s signs off within 14 days with no relief planned.',
                        $employeeName,
                        $rankName,
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
                    'severity' => CrewOperationalAlertSeverity::Warning,
                    'dedupe_key' => 'relief_not_ready:assignment:'.$assignment->id,
                    'title' => 'Relief not ready',
                    'message' => sprintf(
                        '%s · %s on %s signs off within 7 days and relief is not ready (%s).',
                        $employeeName,
                        $rankName,
                        $vesselName,
                        $result->status->label(),
                    ),
                    'context' => $baseContext,
                ];
            }
        }

        return $alerts;
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
                    'current_manning_gap:vessel:%d:rank:%d',
                    $gap['vessel_id'],
                    $gap['rank_id'],
                ),
                'title' => 'Current manning gap',
                'message' => sprintf(
                    '%s · %s is short %d now (%d of %d onboard).',
                    $gap['vessel_name'],
                    $gap['rank_name'],
                    $gap['gap'],
                    $gap['actual_count'],
                    $gap['required_count'],
                ),
                'context' => [
                    'vessel_id' => (int) $gap['vessel_id'],
                    'vessel_name' => (string) $gap['vessel_name'],
                    'rank_id' => (int) $gap['rank_id'],
                    'rank_name' => (string) $gap['rank_name'],
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
                    'projected_manning_gap:vessel:%d:rank:%d',
                    $item['vessel_id'],
                    $item['rank_id'],
                ),
                'title' => 'Projected manning gap',
                'message' => sprintf(
                    '%s · %s has a projected future gap (max short %d)%s.',
                    $item['vessel_name'],
                    $item['rank_name'],
                    $item['maximum_gap'],
                    is_string($item['next_gap_date'] ?? null) && $item['next_gap_date'] !== ''
                        ? ' from '.$item['next_gap_date']
                        : '',
                ),
                'context' => [
                    'vessel_id' => (int) $item['vessel_id'],
                    'vessel_name' => (string) $item['vessel_name'],
                    'rank_id' => (int) $item['rank_id'],
                    'rank_name' => (string) $item['rank_name'],
                    'maximum_gap' => (int) $item['maximum_gap'],
                    'next_gap_date' => $item['next_gap_date'] ?? null,
                    'from' => $from,
                    'to' => $to,
                ],
            ];
        }

        return $alerts;
    }
}
