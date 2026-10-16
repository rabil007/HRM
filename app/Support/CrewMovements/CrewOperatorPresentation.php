<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use Carbon\CarbonInterface;

/**
 * Operator-facing stage, key date, and attention copy.
 *
 * Presentation only. Assignment status values, phase codes, and payroll
 * ownership are unchanged.
 */
final class CrewOperatorPresentation
{
    /**
     * @param  list<array{code: string, severity: string, label: string, message: string, date: string|null}>  $warnings
     * @return array{
     *     operator_stage: array{label: string, phase_code: string|null, phase_label: string|null, secondary: string|null},
     *     key_operational_date: array{label: string, date: string|null, kind: string},
     *     operator_attention: array{label: string, detail: string|null, tone: string, extra_count: int, items: list<array{code: string, label: string, detail: string}>}
     * }
     */
    public static function forAssignment(
        CrewAssignment $assignment,
        string $timezone,
        array $warnings = [],
        ?CrewMobilisationReadinessResult $readiness = null,
        ?CrewReliefReadinessResult $relief = null,
    ): array {
        return [
            'operator_stage' => self::stage($assignment),
            'key_operational_date' => self::keyDate($assignment, $timezone),
            'operator_attention' => self::attention($assignment, $warnings, $readiness, $relief),
        ];
    }

    /**
     * @return array{label: string, phase_code: string|null, phase_label: string|null, secondary: string|null}
     */
    public static function stage(CrewAssignment $assignment): array
    {
        $phase = $assignment->currentPhase;
        $phaseCode = $phase?->phase_code;
        $phaseLabel = $phaseCode?->label();
        $secondary = $phaseCode !== null
            ? strtoupper($phaseCode->value).' · '.$phaseLabel
            : null;

        $label = match ($assignment->status) {
            CrewAssignmentStatus::Draft => 'Not Started',
            CrewAssignmentStatus::Completed => 'Completed',
            CrewAssignmentStatus::Cancelled => 'Cancelled',
            CrewAssignmentStatus::Active => self::activeStageLabel($phaseCode),
        };

        if ($assignment->status !== CrewAssignmentStatus::Active) {
            $secondary = null;
        }

        return [
            'label' => $label,
            'phase_code' => $phaseCode?->value,
            'phase_label' => $phaseLabel,
            'secondary' => $secondary,
        ];
    }

    /**
     * @return array{label: string, date: string|null, kind: string}
     */
    public static function keyDate(CrewAssignment $assignment, string $timezone): array
    {
        $status = $assignment->status;

        if ($status === CrewAssignmentStatus::Draft) {
            return self::dated(
                'Planned Arrival',
                self::date($assignment->planned_arrival_at, $timezone),
                'planned',
            ) ?? self::dated('Created', self::date($assignment->created_at, $timezone), 'actual')
                ?? self::missing('Created');
        }

        if ($status === CrewAssignmentStatus::Completed) {
            return self::latestOperationalDate($assignment, $timezone, 'Assignment Completed', self::date($assignment->closed_at, $timezone));
        }

        if ($status === CrewAssignmentStatus::Cancelled) {
            $cancelled = self::date($assignment->closed_at, $timezone);

            if ($cancelled !== null) {
                return self::dated('Cancelled', $cancelled, 'actual');
            }

            return self::latestOperationalDate($assignment, $timezone, 'Cancelled', null);
        }

        $phase = $assignment->currentPhase?->phase_code;

        return match ($phase) {
            CrewPhaseCode::PreArrival => self::dated('Expected Arrival', self::date($assignment->planned_arrival_at, $timezone), 'planned')
                ?? self::dated('P0 Started', self::date($assignment->currentPhase?->actual_start_at, $timezone), 'actual')
                ?? self::missing('Expected Arrival'),
            CrewPhaseCode::JoinStandby => self::dated('Expected Vessel Join', self::date($assignment->planned_join_at, $timezone), 'planned')
                ?? self::dated('Actual Arrival', self::arrivalDate($assignment, $timezone), 'actual')
                ?? self::missing('Expected Vessel Join'),
            CrewPhaseCode::Training => self::dated('Expected Training Completion', self::date(self::latestPhase($assignment, CrewPhaseCode::Training)?->planned_end_at, $timezone), 'planned')
                ?? self::dated('Training Started', self::date(self::latestPhase($assignment, CrewPhaseCode::Training)?->actual_start_at ?? $assignment->currentPhase?->actual_start_at, $timezone), 'actual')
                ?? self::missing('Expected Training Completion'),
            CrewPhaseCode::OnVessel => self::dated('Planned Sign-Off', self::date($assignment->planned_signoff_at, $timezone), 'planned')
                ?? self::dated('Joined Vessel', self::date(self::latestPhase($assignment, CrewPhaseCode::OnVessel)?->actual_start_at, $timezone), 'actual')
                ?? self::missing('Planned Sign-Off'),
            CrewPhaseCode::DemobStandby => self::dated('Planned Travel Home', self::date($assignment->planned_travel_at, $timezone), 'planned')
                ?? self::dated('Disembarked', self::date(self::latestPhase($assignment, CrewPhaseCode::OnVessel)?->actual_end_at, $timezone), 'actual')
                ?? self::missing('Planned Travel Home'),
            CrewPhaseCode::HomeRedeploy => self::dated('Returned Home', self::date(self::latestPhase($assignment, CrewPhaseCode::HomeRedeploy)?->actual_start_at ?? $assignment->currentPhase?->actual_start_at, $timezone), 'actual')
                ?? self::missing('Returned Home'),
            default => self::dated('Phase Started', self::date($assignment->currentPhase?->actual_start_at, $timezone), 'actual')
                ?? self::missing('Key Date'),
        };
    }

    /**
     * @param  list<array{code: string, severity: string, label: string, message: string, date: string|null}>  $warnings
     * @return array{label: string, detail: string|null, tone: string, extra_count: int, items: list<array{code: string, label: string, detail: string}>}
     */
    public static function attention(
        CrewAssignment $assignment,
        array $warnings,
        ?CrewMobilisationReadinessResult $readiness,
        ?CrewReliefReadinessResult $relief,
    ): array {
        $items = [];

        foreach ($warnings as $warning) {
            $mapped = self::mapWarning($assignment, $warning);

            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        $document = self::documentAttention($readiness);

        if ($document !== null) {
            $items[] = $document;
        }

        $reliefItem = self::reliefAttention($assignment, $relief);

        if ($reliefItem !== null) {
            $items[] = $reliefItem;
        }

        usort($items, fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        $public = array_map(fn (array $item): array => [
            'code' => $item['code'],
            'label' => $item['label'],
            'detail' => $item['detail'],
        ], $items);

        if ($public === []) {
            return [
                'label' => 'No Action Required',
                'detail' => null,
                'tone' => 'clear',
                'extra_count' => 0,
                'items' => [],
            ];
        }

        $primary = $public[0];
        $tone = $items[0]['tone'];

        return [
            'label' => $primary['label'],
            'detail' => $primary['detail'],
            'tone' => $tone,
            'extra_count' => max(0, count($public) - 1),
            'items' => $public,
        ];
    }

    private static function activeStageLabel(?CrewPhaseCode $phase): string
    {
        return match ($phase) {
            CrewPhaseCode::PreArrival => 'Awaiting Arrival',
            CrewPhaseCode::JoinStandby => 'Awaiting Vessel Joining',
            CrewPhaseCode::Training => 'In Training',
            CrewPhaseCode::OnVessel => 'On Vessel',
            CrewPhaseCode::DemobStandby => 'Awaiting Home / Redeployment',
            CrewPhaseCode::HomeRedeploy => 'Home / Awaiting Closure',
            default => $phase?->label() ?? 'Active',
        };
    }

    /**
     * @param  array{code: string, severity: string, label: string, message: string, date: string|null}  $warning
     * @return array{code: string, label: string, detail: string, priority: int, tone: string}|null
     */
    private static function mapWarning(CrewAssignment $assignment, array $warning): ?array
    {
        $code = (string) ($warning['code'] ?? '');
        $detail = (string) ($warning['message'] ?? $warning['label'] ?? '');
        $severity = (string) ($warning['severity'] ?? 'warning');
        $tone = $severity === 'critical' ? 'critical' : 'warning';
        $phase = $assignment->currentPhase?->phase_code;

        return match ($code) {
            'planned_signoff_overdue', 'tour_overdue', 'tour_due_today' => self::item($code, 'Sign-Off Overdue', $detail, 10, 'critical'),
            'tour_due_within_7_days', 'tour_due_within_14_days' => self::item($code, 'Sign-Off Due Soon', $detail, 40, $tone),
            'missing_planned_signoff' => self::item($code, 'Sign-Off Not Planned', $detail, 60, 'warning'),
            'planned_join_overdue' => self::item($code, 'Join Overdue', $detail, 30, 'critical'),
            'phase_stale' => $phase === CrewPhaseCode::Training
                ? self::item('training_attention', 'Training Attention', $detail, 45, 'warning')
                : self::item($code, (string) ($warning['label'] ?? 'Needs Attention'), $detail, 55, $tone),
            'phase_start_in_future', 'missing_current_phase', 'missing_vessel', 'missing_rank', 'draft_stale', 'missing_tour_of_duty' => self::item(
                $code,
                (string) ($warning['label'] ?? 'Needs Attention'),
                $detail,
                $severity === 'critical' ? 20 : 65,
                $tone,
            ),
            default => $code === '' ? null : self::item($code, (string) ($warning['label'] ?? 'Needs Attention'), $detail, 70, $tone),
        };
    }

    /**
     * @return array{code: string, label: string, detail: string, priority: int, tone: string}|null
     */
    private static function documentAttention(?CrewMobilisationReadinessResult $readiness): ?array
    {
        if ($readiness === null || ! $readiness->applies) {
            return null;
        }

        $problems = $readiness->problems;

        if ($problems !== []) {
            $codes = array_column($problems, 'code');
            $missingOrExpired = array_intersect($codes, ['document_missing', 'document_expired']);
            $label = $missingOrExpired !== [] ? 'Documents Missing / Expired' : 'Document Attention';
            $count = count($problems);
            $detail = $count === 1
                ? (string) ($problems[0]['message'] ?? $problems[0]['label'] ?? 'A configured document check needs attention.')
                : sprintf('%d configured document checks need attention. This does not block movement.', $count);

            return self::item('document_issue', $label, $detail, 25, 'warning');
        }

        if (! $readiness->hasConfiguredChecks()) {
            return self::item(
                'no_checks_configured',
                'No Checks Configured',
                'No mobilisation document checks are configured. This does not mean the crew member is cleared.',
                80,
                'info',
            );
        }

        return null;
    }

    /**
     * @return array{code: string, label: string, detail: string, priority: int, tone: string}|null
     */
    private static function reliefAttention(CrewAssignment $assignment, ?CrewReliefReadinessResult $relief): ?array
    {
        if ($relief === null || $assignment->status !== CrewAssignmentStatus::Active) {
            return null;
        }

        if ($assignment->currentPhase?->phase_code !== CrewPhaseCode::OnVessel) {
            return null;
        }

        if ($relief->status === CrewReliefStatus::NoRelief) {
            return self::item(
                'relief_not_assigned',
                'Relief Not Assigned',
                'No operational relief is assigned. Plan Relief remains optional and does not block disembarkation.',
                35,
                'warning',
            );
        }

        if (in_array($relief->status, CrewReliefStatus::notReady(), true)) {
            return self::item(
                'relief_not_ready',
                'Relief Not Ready',
                'The planned relief is not ready to join. This does not block disembarkation.',
                36,
                'warning',
            );
        }

        return null;
    }

    /**
     * @return array{code: string, label: string, detail: string, priority: int, tone: string}
     */
    private static function item(string $code, string $label, string $detail, int $priority, string $tone): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'detail' => $detail,
            'priority' => $priority,
            'tone' => $tone,
        ];
    }

    /**
     * @return array{label: string, date: string|null, kind: string}|null
     */
    private static function dated(string $label, ?string $date, string $kind): ?array
    {
        if ($date === null || $date === '') {
            return null;
        }

        return [
            'label' => $label,
            'date' => $date,
            'kind' => $kind,
        ];
    }

    /**
     * @return array{label: string, date: string|null, kind: string}
     */
    private static function missing(string $label): array
    {
        return [
            'label' => $label,
            'date' => null,
            'kind' => 'planned',
        ];
    }

    /**
     * @return array{label: string, date: string|null, kind: string}
     */
    private static function latestOperationalDate(
        CrewAssignment $assignment,
        string $timezone,
        string $closedLabel,
        ?string $closedAt,
    ): array {
        $candidates = array_values(array_filter([
            $closedAt !== null ? ['label' => $closedLabel, 'date' => $closedAt, 'rank' => 5] : null,
            self::candidate('Returned Home', self::date(self::latestPhase($assignment, CrewPhaseCode::HomeRedeploy)?->actual_start_at, $timezone), 4),
            self::candidate('Disembarked', self::date(self::latestPhase($assignment, CrewPhaseCode::OnVessel)?->actual_end_at, $timezone), 3),
            self::candidate('Joined Vessel', self::date(self::latestPhase($assignment, CrewPhaseCode::OnVessel)?->actual_start_at, $timezone), 2),
            self::candidate('Arrived', self::arrivalDate($assignment, $timezone), 1),
        ]));

        if ($candidates === []) {
            return self::missing($closedLabel);
        }

        usort($candidates, function (array $left, array $right): int {
            $byDate = strcmp($right['date'], $left['date']);

            return $byDate !== 0 ? $byDate : $right['rank'] <=> $left['rank'];
        });

        return [
            'label' => $candidates[0]['label'],
            'date' => $candidates[0]['date'],
            'kind' => 'actual',
        ];
    }

    /**
     * @return array{label: string, date: string, rank: int}|null
     */
    private static function candidate(string $label, ?string $date, int $rank): ?array
    {
        if ($date === null || $date === '') {
            return null;
        }

        return [
            'label' => $label,
            'date' => $date,
            'rank' => $rank,
        ];
    }

    private static function arrivalDate(CrewAssignment $assignment, string $timezone): ?string
    {
        return CrewArrivalResolver::date($assignment, $timezone);
    }

    private static function latestPhase(CrewAssignment $assignment, CrewPhaseCode $code): ?CrewAssignmentPhase
    {
        if ($assignment->relationLoaded('phases')) {
            return $assignment->phases
                ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === $code)
                ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
                ->first();
        }

        $current = $assignment->relationLoaded('currentPhase')
            ? $assignment->currentPhase
            : null;

        return $current?->phase_code === $code ? $current : null;
    }

    private static function date(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof CarbonInterface) {
            return null;
        }

        return $value->copy()->timezone($timezone)->toDateString();
    }
}
