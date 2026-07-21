<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;

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
        /** @var array<int, array<string, mixed>> $grouped */
        $grouped = [];

        foreach ($preparation->lines as $line) {
            $employeeId = (int) $line->employee_id;

            if (! isset($grouped[$employeeId])) {
                $grouped[$employeeId] = [
                    'employee_id' => $employeeId,
                    'employee_number' => $line->employee?->employee_no,
                    'employee_name' => $line->employee?->name,
                    'rank' => $line->assignment?->rank?->name
                        ?? $line->employee?->position?->title,
                    'assignment_id' => $line->crew_assignment_id,
                    'assignment_number' => $line->assignment?->assignment_no,
                    'vessel' => $line->assignment?->vessel?->name,
                    'sign_on_standby_from' => null,
                    'sign_on_standby_to' => null,
                    'sign_on_standby_days' => 0.0,
                    'onsite_from' => null,
                    'onsite_to' => null,
                    'onsite_days' => 0.0,
                    'sign_off_standby_from' => null,
                    'sign_off_standby_to' => null,
                    'sign_off_standby_days' => 0.0,
                    'total_payable_days' => 0.0,
                    'blocking_warning_count' => 0,
                    'informational_warning_count' => 0,
                    'lines' => [],
                ];
            }

            $warning = $this->warningPayload($line->warning_code);

            if ($warning !== null) {
                if ($warning['is_blocking']) {
                    $grouped[$employeeId]['blocking_warning_count']++;
                } else {
                    $grouped[$employeeId]['informational_warning_count']++;
                }
            }

            $days = (float) $line->days;

            if ($days > 0 && $line->pay_category !== null) {
                $this->accumulatePayableDays($grouped[$employeeId], $line, $days);
            }

            $grouped[$employeeId]['lines'][] = [
                'id' => $line->id,
                'phase_code' => $line->phase_code?->value,
                'phase_label' => $line->phase_code?->label(),
                'pay_category' => $line->pay_category?->value,
                'pay_category_label' => $line->pay_category?->label(),
                'from_date' => $line->from_date?->toDateString(),
                'to_date' => $line->to_date?->toDateString(),
                'days' => $this->formatDays($days),
                'source_actual_start' => $line->source_actual_start_at?->toDateString(),
                'source_actual_end' => $line->source_actual_end_at?->toDateString(),
                'warning' => $warning,
                'remarks' => $line->remarks,
            ];
        }

        return array_values($grouped);
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function accumulatePayableDays(
        array &$employee,
        CrewTimesheetPreparationLine $line,
        float $days,
    ): void {
        $from = $line->from_date?->toDateString();
        $to = $line->to_date?->toDateString();

        match ($line->pay_category) {
            CrewTimesheetPayCategory::SignOnStandby => $this->accumulateCategory(
                $employee,
                'sign_on_standby',
                $from,
                $to,
                $days,
            ),
            CrewTimesheetPayCategory::Onsite => $this->accumulateCategory(
                $employee,
                'onsite',
                $from,
                $to,
                $days,
            ),
            CrewTimesheetPayCategory::SignOffStandby => $this->accumulateCategory(
                $employee,
                'sign_off_standby',
                $from,
                $to,
                $days,
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function accumulateCategory(
        array &$employee,
        string $prefix,
        ?string $from,
        ?string $to,
        float $days,
    ): void {
        $fromKey = "{$prefix}_from";
        $toKey = "{$prefix}_to";
        $daysKey = "{$prefix}_days";

        if ($from !== null && ($employee[$fromKey] === null || $from < $employee[$fromKey])) {
            $employee[$fromKey] = $from;
        }

        if ($to !== null && ($employee[$toKey] === null || $to > $employee[$toKey])) {
            $employee[$toKey] = $to;
        }

        $employee[$daysKey] = round((float) $employee[$daysKey] + $days, 2);
        $employee['total_payable_days'] = round((float) $employee['total_payable_days'] + $days, 2);
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
