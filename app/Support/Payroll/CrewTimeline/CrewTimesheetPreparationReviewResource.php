<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;
use App\Support\CrewMovements\CrewDateProvenance;
use Illuminate\Support\Collection;

final class CrewTimesheetPreparationReviewResource
{
    public function __construct(
        private readonly CrewTimelineFreshnessChecker $freshnessChecker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
    ): array {
        $isFresh = $this->freshnessChecker->isFresh($preparation, $period);
        $employees = $this->employeeSummaries($preparation);
        $summary = $this->summaryTotals($employees);

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
                'status' => $period->status?->value,
                'status_label' => $period->status?->label(),
            ],
            'preparation' => [
                'id' => $preparation->id,
                'version' => $preparation->version,
                'status' => $preparation->status->value,
                'status_label' => $preparation->status->label(),
                'cutoff_date' => $preparation->cutoff_date?->toDateString(),
                'source_hash' => $preparation->source_hash,
                'is_fresh' => $isFresh,
                'is_stale' => ! $isFresh,
                'is_latest' => $this->isLatest($preparation),
                'prepared_by' => $this->userPayload($preparation->preparedBy),
                'prepared_at' => $preparation->prepared_at?->toIso8601String(),
                'submitted_by' => $this->userPayload($preparation->submittedBy),
                'submitted_at' => $preparation->submitted_at?->toIso8601String(),
                'approved_by' => $this->userPayload($preparation->approvedBy),
                'approved_at' => $preparation->approved_at?->toIso8601String(),
                'returned_by' => $this->userPayload($preparation->returnedBy),
                'returned_at' => $preparation->returned_at?->toIso8601String(),
                'applied_by' => $this->userPayload($preparation->appliedBy),
                'applied_at' => $preparation->applied_at?->toIso8601String(),
                'linked_timesheet_count' => (int) ($preparation->linked_timesheet_count ?? 0),
                'decision_notes' => $preparation->decision_notes,
            ],
            'summary' => $summary,
            'employees' => $employees,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeeSummaries(CrewTimesheetPreparation $preparation): array
    {
        /** @var Collection<int, Collection<int, CrewTimesheetPreparationLine>> $linesByEmployee */
        $linesByEmployee = $preparation->lines->groupBy(
            fn (CrewTimesheetPreparationLine $line): int => (int) $line->employee_id,
        );

        $employees = [];

        foreach ($linesByEmployee as $employeeId => $employeeLines) {
            /** @var Collection<int, CrewTimesheetPreparationLine> $employeeLines */
            $first = $employeeLines->first();
            $assignments = $this->assignmentSummaries($employeeLines);
            $flatLines = $employeeLines
                ->map(fn (CrewTimesheetPreparationLine $line): array => $this->flatLinePayload($line))
                ->values()
                ->all();

            $blocking = 0;
            $informational = 0;
            $signOnDays = 0.0;
            $onsiteDays = 0.0;
            $signOffDays = 0.0;
            $signOnFrom = null;
            $signOnTo = null;
            $onsiteFrom = null;
            $onsiteTo = null;
            $signOffFrom = null;
            $signOffTo = null;
            $totalPayable = 0.0;

            foreach ($employeeLines as $line) {
                $warning = $this->warningPayload($line->warning_code);

                if ($warning !== null) {
                    if ($warning['is_blocking']) {
                        $blocking++;
                    } else {
                        $informational++;
                    }
                }

                $days = (float) $line->days;

                if ($days <= 0 || $line->pay_category === null) {
                    continue;
                }

                $from = $line->from_date?->toDateString();
                $to = $line->to_date?->toDateString();

                match ($line->pay_category) {
                    CrewTimesheetPayCategory::SignOnStandby => $this->accumulateCategoryValues(
                        $signOnFrom,
                        $signOnTo,
                        $signOnDays,
                        $totalPayable,
                        $from,
                        $to,
                        $days,
                    ),
                    CrewTimesheetPayCategory::Onsite => $this->accumulateCategoryValues(
                        $onsiteFrom,
                        $onsiteTo,
                        $onsiteDays,
                        $totalPayable,
                        $from,
                        $to,
                        $days,
                    ),
                    CrewTimesheetPayCategory::SignOffStandby => $this->accumulateCategoryValues(
                        $signOffFrom,
                        $signOffTo,
                        $signOffDays,
                        $totalPayable,
                        $from,
                        $to,
                        $days,
                    ),
                    default => null,
                };
            }

            $assignmentCount = count($assignments);
            $primaryAssignment = $assignmentCount === 1 ? ($assignments[0] ?? null) : null;

            $employees[] = [
                'employee_id' => (int) $employeeId,
                'employee_number' => $first?->employee?->employee_no,
                'employee_name' => $first?->employee?->name,
                'rank' => $primaryAssignment['rank']
                    ?? $assignments[0]['rank']
                    ?? $first?->assignment?->rank?->name
                    ?? $first?->employee?->position?->title,
                'assignment_id' => $primaryAssignment['id'] ?? null,
                'assignment_number' => $primaryAssignment['assignment_number'] ?? null,
                'vessel' => $primaryAssignment['vessel'] ?? null,
                'assignment_count' => $assignmentCount,
                'sign_on_standby_from' => $signOnFrom,
                'sign_on_standby_to' => $signOnTo,
                'sign_on_standby_days' => round($signOnDays, 2),
                'onsite_from' => $onsiteFrom,
                'onsite_to' => $onsiteTo,
                'onsite_days' => round($onsiteDays, 2),
                'sign_off_standby_from' => $signOffFrom,
                'sign_off_standby_to' => $signOffTo,
                'sign_off_standby_days' => round($signOffDays, 2),
                'total_payable_days' => round($totalPayable, 2),
                'blocking_warning_count' => $blocking,
                'informational_warning_count' => $informational,
                'assignments' => $assignments,
                'lines' => $flatLines,
            ];
        }

        return $employees;
    }

    /**
     * @param  Collection<int, CrewTimesheetPreparationLine>  $employeeLines
     * @return list<array<string, mixed>>
     */
    private function assignmentSummaries(Collection $employeeLines): array
    {
        /** @var Collection<int|string, Collection<int, CrewTimesheetPreparationLine>> $byAssignment */
        $byAssignment = $employeeLines->groupBy(
            fn (CrewTimesheetPreparationLine $line): int => (int) ($line->crew_assignment_id ?? 0),
        );

        $assignments = [];

        foreach ($byAssignment as $assignmentId => $assignmentLines) {
            /** @var Collection<int, CrewTimesheetPreparationLine> $assignmentLines */
            $assignment = $assignmentLines->first()?->assignment;
            $phases = $this->phaseSummaries($assignmentLines);

            $assignments[] = [
                'id' => $assignmentId > 0 ? (int) $assignmentId : null,
                'assignment_number' => $assignment?->assignment_no,
                'source' => $assignment?->source,
                'source_label' => $this->assignmentSourceLabel($assignment?->source),
                'status' => $assignment?->status?->value,
                'status_label' => $assignment?->status instanceof CrewAssignmentStatus
                    ? $assignment->status->label()
                    : null,
                'previous_assignment_id' => $assignment?->previous_assignment_id,
                'previous_assignment_number' => $assignment?->previousAssignment?->assignment_no,
                'previous_vessel' => $assignment?->previousAssignment?->vessel?->name,
                'vessel' => $assignment?->vessel?->name,
                'client' => $assignment?->client?->name,
                'rank' => $assignment?->rank?->name
                    ?? $assignmentLines->first()?->employee?->position?->title,
                'phases' => $phases,
            ];
        }

        return $this->orderAssignments($assignments);
    }

    /**
     * @param  Collection<int, CrewTimesheetPreparationLine>  $assignmentLines
     * @return list<array<string, mixed>>
     */
    private function phaseSummaries(Collection $assignmentLines): array
    {
        /** @var Collection<string, Collection<int, CrewTimesheetPreparationLine>> $byPhase */
        $byPhase = $assignmentLines->groupBy(
            function (CrewTimesheetPreparationLine $line): string {
                if ($line->crew_assignment_phase_id !== null) {
                    return 'phase:'.(int) $line->crew_assignment_phase_id;
                }

                return 'line:'.(int) $line->id;
            },
        );

        $phases = [];

        foreach ($byPhase as $phaseLines) {
            /** @var Collection<int, CrewTimesheetPreparationLine> $phaseLines */
            $phases[] = $this->phasePayload($phaseLines);
        }

        usort($phases, function (array $left, array $right): int {
            $sequenceCompare = ($left['sequence'] ?? PHP_INT_MAX) <=> ($right['sequence'] ?? PHP_INT_MAX);

            if ($sequenceCompare !== 0) {
                return $sequenceCompare;
            }

            $leftStart = $left['actual_start'] ?? $left['planned_start'] ?? '9999-12-31';
            $rightStart = $right['actual_start'] ?? $right['planned_start'] ?? '9999-12-31';

            $dateCompare = $leftStart <=> $rightStart;

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ($left['id'] ?? 0) <=> ($right['id'] ?? 0);
        });

        $codeCounts = [];

        foreach ($phases as $phase) {
            $code = $phase['phase_code'] ?? 'unknown';
            $codeCounts[$code] = ($codeCounts[$code] ?? 0) + 1;
        }

        $codeIndexes = [];

        foreach ($phases as $index => $phase) {
            $code = $phase['phase_code'] ?? 'unknown';
            $codeIndexes[$code] = ($codeIndexes[$code] ?? 0) + 1;
            $phases[$index]['occurrence'] = $codeCounts[$code] > 1
                ? $codeIndexes[$code]
                : null;
            $phases[$index]['occurrence_count'] = $codeCounts[$code];
        }

        return $phases;
    }

    /**
     * @param  Collection<int, CrewTimesheetPreparationLine>  $phaseLines
     * @return array<string, mixed>
     */
    private function phasePayload(Collection $phaseLines): array
    {
        $first = $phaseLines->first();
        $phase = $first?->phase;
        $payrollLines = [];
        $warnings = [];
        $remarks = [];
        $payableDays = 0.0;
        $primaryTreatment = null;
        $excludedTreatment = null;
        $payableFrom = null;
        $payableTo = null;
        $isOperational = false;

        foreach ($phaseLines as $line) {
            $linePayload = $this->flatLinePayload($line);
            $payrollLines[] = $linePayload;
            $warning = $linePayload['warning'];
            $days = (float) $line->days;
            $isWarningOnly = $warning !== null && $days <= 0;

            if (! $isWarningOnly) {
                $isOperational = true;
            }

            if ($warning !== null) {
                $warnings[] = [
                    ...$warning,
                    'remarks' => $line->remarks,
                    'from_date' => $line->from_date?->toDateString(),
                    'to_date' => $line->to_date?->toDateString(),
                    'line_id' => $line->id,
                ];
            }

            if (filled($line->remarks) && $warning === null) {
                $remarks[] = $line->remarks;
            }

            if ($days > 0 && $line->pay_category !== null && $line->pay_category !== CrewTimesheetPayCategory::Excluded) {
                $payableDays += $days;
                $from = $line->from_date?->toDateString();
                $to = $line->to_date?->toDateString();

                if ($from !== null && ($payableFrom === null || $from < $payableFrom)) {
                    $payableFrom = $from;
                }

                if ($to !== null && ($payableTo === null || $to > $payableTo)) {
                    $payableTo = $to;
                }

                if ($primaryTreatment === null) {
                    $primaryTreatment = [
                        'pay_category' => $line->pay_category->value,
                        'pay_category_label' => $line->pay_category->label(),
                        'from_date' => $from,
                        'to_date' => $to,
                        'days' => $this->formatDays($days),
                    ];
                }
            }

            if ($line->pay_category === CrewTimesheetPayCategory::Excluded && ! $isWarningOnly) {
                $excludedTreatment = [
                    'pay_category' => $line->pay_category->value,
                    'pay_category_label' => $line->pay_category->label(),
                    'from_date' => $line->from_date?->toDateString(),
                    'to_date' => $line->to_date?->toDateString(),
                    'days' => $this->formatDays($days),
                ];
            }
        }

        if ($primaryTreatment === null && $excludedTreatment !== null) {
            $primaryTreatment = $excludedTreatment;
            $excludedTreatment = null;
        }

        $phaseCode = $phase?->phase_code ?? $first?->phase_code;
        $timezone = (string) config('app.timezone', 'UTC');
        $planned = CrewDateProvenance::phasePlanned($phase, $first?->assignment, $timezone);
        $actual = CrewDateProvenance::phaseActual($phase, $timezone);

        $hasWarningOnlyRanges = $phaseLines->contains(
            fn (CrewTimesheetPreparationLine $line): bool => $line->warning_code !== null && (float) $line->days <= 0,
        );
        $hasPayableAllocation = $payableFrom !== null || $payableTo !== null
            || ($excludedTreatment !== null && ! $hasWarningOnlyRanges);

        $payrollFrom = $payableFrom ?? $excludedTreatment['from_date'] ?? $warnings[0]['from_date'] ?? null;
        $payrollTo = $payableTo ?? $excludedTreatment['to_date'] ?? $warnings[0]['to_date'] ?? null;
        $payrollOrigin = match (true) {
            $payableFrom !== null || $payableTo !== null => CrewDateProvenance::PayrollAllocation,
            $hasWarningOnlyRanges && $warnings !== [] => CrewDateProvenance::WarningRange,
            $excludedTreatment !== null => CrewDateProvenance::PayrollAllocation,
            default => null,
        };

        return [
            'id' => $phase?->id ?? $first?->crew_assignment_phase_id,
            'phase_code' => $phaseCode?->value,
            'phase_code_display' => $this->phaseCodeDisplay($phaseCode),
            'phase_label' => $phaseCode?->label(),
            'sequence' => $phase?->sequence ?? null,
            'status' => $phase?->status instanceof CrewPhaseStatus ? $phase->status->value : null,
            'status_label' => $phase?->status instanceof CrewPhaseStatus ? $phase->status->label() : null,
            'planned_start' => $planned['start'],
            'planned_end' => $planned['end'],
            'planned_date_origin' => $planned['origin'],
            'planned_date_origin_label' => $planned['origin_label'],
            'actual_start' => $actual['start'],
            'actual_end' => $actual['end'],
            'actual_date_origin' => $actual['origin'],
            'actual_date_origin_label' => $actual['origin_label'],
            'payroll_from' => $payrollFrom,
            'payroll_to' => $payrollTo,
            'payroll_date_origin' => $payrollOrigin,
            'payroll_date_origin_label' => CrewDateProvenance::label($payrollOrigin),
            'payroll_period_label' => match ($payrollOrigin) {
                CrewDateProvenance::WarningRange => 'Affected period',
                CrewDateProvenance::PayrollAllocation => 'Payroll allocation',
                default => null,
            },
            'payroll_lines' => $payrollLines,
            'primary_treatment' => $primaryTreatment,
            'excluded_treatment' => $excludedTreatment,
            'payable_from' => $payableFrom,
            'payable_to' => $payableTo,
            'payable_days' => $this->formatDays($payableDays),
            'is_operational' => $isOperational,
            'warnings' => $warnings,
            'remarks' => array_values(array_unique($remarks)),
            'occurrence' => null,
            'occurrence_count' => 1,
            'has_planned_schedule' => $planned['start'] !== null || $planned['end'] !== null,
            'has_payroll_period' => $hasPayableAllocation || ($payrollFrom !== null || $payrollTo !== null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flatLinePayload(CrewTimesheetPreparationLine $line): array
    {
        return [
            'id' => $line->id,
            'crew_assignment_id' => $line->crew_assignment_id,
            'crew_assignment_phase_id' => $line->crew_assignment_phase_id,
            'phase_code' => $line->phase_code?->value,
            'phase_label' => $line->phase_code?->label(),
            'pay_category' => $line->pay_category?->value,
            'pay_category_label' => $line->pay_category?->label(),
            'from_date' => $line->from_date?->toDateString(),
            'to_date' => $line->to_date?->toDateString(),
            'days' => $this->formatDays((float) $line->days),
            'source_actual_start' => $line->source_actual_start_at?->toDateString(),
            'source_actual_end' => $line->source_actual_end_at?->toDateString(),
            'warning' => $this->warningPayload($line->warning_code),
            'remarks' => $line->remarks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function orderAssignments(array $assignments): array
    {
        if ($assignments === []) {
            return [];
        }

        $byId = [];

        foreach ($assignments as $assignment) {
            $id = $assignment['id'] ?? ('null-'.count($byId));
            $byId[$id] = $assignment;
        }

        $ordered = [];
        $visited = [];

        $roots = array_values(array_filter(
            $assignments,
            function (array $assignment) use ($byId): bool {
                $previousId = $assignment['previous_assignment_id'] ?? null;

                return $previousId === null || ! isset($byId[$previousId]);
            },
        ));

        usort($roots, function (array $left, array $right): int {
            $leftStart = $this->assignmentSortKey($left);
            $rightStart = $this->assignmentSortKey($right);

            return $leftStart <=> $rightStart;
        });

        $appendChain = function (array $assignment) use (&$appendChain, &$ordered, &$visited, $byId): void {
            $id = $assignment['id'] ?? null;
            $visitKey = $id ?? spl_object_id((object) $assignment);

            if (isset($visited[$visitKey])) {
                return;
            }

            $visited[$visitKey] = true;
            $ordered[] = $assignment;

            if ($id === null) {
                return;
            }

            $children = array_values(array_filter(
                $byId,
                fn (array $candidate): bool => ($candidate['previous_assignment_id'] ?? null) === $id,
            ));

            usort($children, fn (array $left, array $right): int => $this->assignmentSortKey($left) <=> $this->assignmentSortKey($right));

            foreach ($children as $child) {
                $appendChain($child);
            }
        };

        foreach ($roots as $root) {
            $appendChain($root);
        }

        foreach ($assignments as $assignment) {
            $appendChain($assignment);
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function assignmentSortKey(array $assignment): string
    {
        foreach ($assignment['phases'] ?? [] as $phase) {
            $start = $phase['actual_start'] ?? $phase['planned_start'] ?? $phase['payable_from'] ?? null;

            if ($start !== null) {
                return $start;
            }
        }

        return sprintf('%010d', (int) ($assignment['id'] ?? 0));
    }

    private function assignmentSourceLabel(?string $source): string
    {
        return match ($source) {
            'manual' => 'Manual Assignment',
            'crew_planning' => 'Created from Crew Planning',
            'vessel_transfer' => 'Vessel Transfer',
            'redeployment' => 'Redeployment',
            null, '' => 'Unknown',
            default => str($source)->replace('_', ' ')->title()->toString(),
        };
    }

    private function phaseCodeDisplay(?CrewPhaseCode $phaseCode): ?string
    {
        if ($phaseCode === null) {
            return null;
        }

        return strtoupper($phaseCode->value);
    }

    private function accumulateCategoryValues(
        ?string &$from,
        ?string &$to,
        float &$daysTotal,
        float &$payableTotal,
        ?string $lineFrom,
        ?string $lineTo,
        float $days,
    ): void {
        if ($lineFrom !== null && ($from === null || $lineFrom < $from)) {
            $from = $lineFrom;
        }

        if ($lineTo !== null && ($to === null || $lineTo > $to)) {
            $to = $lineTo;
        }

        $daysTotal = round($daysTotal + $days, 2);
        $payableTotal = round($payableTotal + $days, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @return array{
     *     total_employees: int,
     *     total_sign_on_standby_days: string,
     *     total_onsite_days: string,
     *     total_sign_off_standby_days: string,
     *     blocking_warning_count: int,
     *     informational_warning_count: int
     * }
     */
    private function summaryTotals(array $employees): array
    {
        $signOn = 0.0;
        $onsite = 0.0;
        $signOff = 0.0;
        $blocking = 0;
        $informational = 0;

        foreach ($employees as $employee) {
            $signOn += (float) $employee['sign_on_standby_days'];
            $onsite += (float) $employee['onsite_days'];
            $signOff += (float) $employee['sign_off_standby_days'];
            $blocking += (int) $employee['blocking_warning_count'];
            $informational += (int) $employee['informational_warning_count'];
        }

        return [
            'total_employees' => count($employees),
            'total_sign_on_standby_days' => $this->formatDays($signOn),
            'total_onsite_days' => $this->formatDays($onsite),
            'total_sign_off_standby_days' => $this->formatDays($signOff),
            'blocking_warning_count' => $blocking,
            'informational_warning_count' => $informational,
        ];
    }

    /**
     * @return array{code: string, label: string, is_blocking: bool}|null
     */
    private function warningPayload(?string $warningCode): ?array
    {
        if ($warningCode === null || $warningCode === '') {
            return null;
        }

        $code = CrewTimelineWarningCode::tryFrom($warningCode);

        if ($code === null) {
            return [
                'code' => $warningCode,
                'label' => $warningCode,
                'is_blocking' => false,
            ];
        }

        return [
            'code' => $code->value,
            'label' => $code->label(),
            'is_blocking' => $code->isBlocking(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function userPayload(mixed $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
        ];
    }

    private function isLatest(CrewTimesheetPreparation $preparation): bool
    {
        $latestVersion = (int) CrewTimesheetPreparation::query()
            ->where('company_id', $preparation->company_id)
            ->where('payroll_period_id', $preparation->payroll_period_id)
            ->max('version');

        return (int) $preparation->version === $latestVersion;
    }

    private function formatDays(float $days): string
    {
        return number_format($days, 2, '.', '');
    }
}
