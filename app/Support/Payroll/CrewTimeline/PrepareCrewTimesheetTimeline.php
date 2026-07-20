<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrepareCrewTimesheetTimeline
{
    public function __construct(
        private readonly CrewTimelinePhaseQuery $phaseQuery,
        private readonly CrewTimelineDayAllocator $dayAllocator,
        private readonly CrewTimelineRangeBuilder $rangeBuilder,
        private readonly CrewTimelineIssueDetector $issueDetector,
        private readonly CrewTimelineSourceHasher $sourceHasher,
    ) {}

    public function handle(
        PayrollPeriod $period,
        int $companyId,
        int $preparedByUserId,
        ?CarbonInterface $cutoffDate = null,
    ): CrewTimesheetPreparation {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        if (! $period->isCrew()) {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'Timeline preparation is only available for crew pay periods.',
            ]);
        }

        if ($period->status !== PayrollPeriodStatus::Draft) {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'Timeline preparation is only available for draft pay periods.',
            ]);
        }

        if ($period->crew_timesheet_mode !== CrewTimesheetMode::CrewOperations) {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'This pay period uses Manual / Excel Timesheet mode. Change the timesheet source before preparing a Crew Operations timeline.',
            ]);
        }

        if ($cutoffDate !== null) {
            $cutoff = CarbonImmutable::parse($cutoffDate->toDateString());
            $start = CarbonImmutable::parse($period->start_date->toDateString());
            $end = CarbonImmutable::parse($period->end_date->toDateString());

            if ($cutoff->lt($start) || $cutoff->gt($end)) {
                throw ValidationException::withMessages([
                    'cutoff_date' => 'Cutoff date must fall within the pay period.',
                ]);
            }
        }

        return DB::transaction(function () use ($period, $companyId, $preparedByUserId, $cutoffDate): CrewTimesheetPreparation {
            PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->lockForUpdate()
                ->get();

            $appliedExists = CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->where('status', CrewTimesheetPreparationStatus::Applied)
                ->exists();

            if ($appliedExists) {
                throw ValidationException::withMessages([
                    'payroll_period_id' => 'An applied operational snapshot already exists for this pay period. New timeline versions cannot be prepared until a correction workflow replaces it.',
                ]);
            }

            $nextVersion = (int) CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->max('version');

            $nextVersion++;

            $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $cutoffDate);
            $phases = $this->phaseQuery->overlappingPhases($period, $effectiveEnd);
            $sourceHash = $this->sourceHasher->hash($period, $cutoffDate, $phases);
            $issues = $this->issueDetector->detect($period, $phases, $effectiveEnd, $companyId);
            $allocatedDays = $this->dayAllocator->allocate($period, $phases, $effectiveEnd, $companyId);
            $ranges = $this->rangeBuilder->build($allocatedDays);
            $gapIssues = $this->detectTimelineGaps($allocatedDays, $period);

            $preparation = CrewTimesheetPreparation::query()->create([
                'company_id' => $companyId,
                'payroll_period_id' => $period->id,
                'version' => $nextVersion,
                'status' => CrewTimesheetPreparationStatus::Draft,
                'cutoff_date' => $cutoffDate?->toDateString(),
                'source_hash' => $sourceHash,
                'prepared_by' => $preparedByUserId,
                'prepared_at' => now(),
            ]);

            foreach ($ranges as $range) {
                CrewTimesheetPreparationLine::query()->create([
                    'company_id' => $companyId,
                    'crew_timesheet_preparation_id' => $preparation->id,
                    'employee_id' => $range['employee_id'],
                    'crew_assignment_id' => $range['crew_assignment_id'],
                    'crew_assignment_phase_id' => $range['crew_assignment_phase_id'],
                    'phase_code' => $range['phase_code'],
                    'pay_category' => $range['pay_category'],
                    'from_date' => $range['from_date'],
                    'to_date' => $range['to_date'],
                    'days' => $range['days'],
                    'source_actual_start_at' => $range['source_actual_start_at'],
                    'source_actual_end_at' => $range['source_actual_end_at'],
                    'warning_code' => $range['warning_code']?->value,
                    'remarks' => $range['remarks'],
                ]);
            }

            foreach (array_merge($issues, $gapIssues) as $issue) {
                if (($issue['crew_assignment_id'] ?? null) === null) {
                    continue;
                }

                CrewTimesheetPreparationLine::query()->create([
                    'company_id' => $companyId,
                    'crew_timesheet_preparation_id' => $preparation->id,
                    'employee_id' => $issue['employee_id'],
                    'crew_assignment_id' => $issue['crew_assignment_id'],
                    'crew_assignment_phase_id' => $issue['crew_assignment_phase_id'],
                    'phase_code' => $issue['phase_code'] ?? CrewPhaseCode::PreMobilisation,
                    'pay_category' => CrewTimesheetPayCategory::Excluded,
                    'from_date' => $issue['from_date'],
                    'to_date' => $issue['to_date'],
                    'days' => 0,
                    'source_actual_start_at' => null,
                    'source_actual_end_at' => null,
                    'warning_code' => $issue['warning_code']->value,
                    'remarks' => $issue['remarks'],
                ]);
            }

            return $preparation->fresh(['lines']);
        });
    }

    /**
     * @param  list<array{employee_id: int, date: string, crew_assignment_id: int, crew_assignment_phase_id: int, phase_code: CrewPhaseCode}>  $allocatedDays
     * @return list<array{
     *     employee_id: int,
     *     crew_assignment_id: int,
     *     crew_assignment_phase_id: int,
     *     phase_code: CrewPhaseCode,
     *     warning_code: CrewTimelineWarningCode,
     *     remarks: string,
     *     from_date: string,
     *     to_date: string
     * }>
     */
    private function detectTimelineGaps(array $allocatedDays, PayrollPeriod $period): array
    {
        $byEmployee = [];

        foreach ($allocatedDays as $day) {
            if ($day['pay_category'] === CrewTimesheetPayCategory::Excluded) {
                continue;
            }

            $byEmployee[$day['employee_id']][] = $day;
        }

        $gaps = [];

        foreach ($byEmployee as $employeeId => $days) {
            usort($days, fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            $dates = array_values(array_unique(array_column($days, 'date')));

            for ($i = 1, $count = count($dates); $i < $count; $i++) {
                $previous = CarbonImmutable::parse($dates[$i - 1]);
                $current = CarbonImmutable::parse($dates[$i]);
                $expected = $previous->addDay();

                if ($current->gt($expected)) {
                    $gapFrom = $expected->toDateString();
                    $gapTo = $current->subDay()->toDateString();
                    $sample = $days[0];

                    $gaps[] = [
                        'employee_id' => (int) $employeeId,
                        'crew_assignment_id' => $sample['crew_assignment_id'],
                        'crew_assignment_phase_id' => $sample['crew_assignment_phase_id'],
                        'phase_code' => $sample['phase_code'],
                        'warning_code' => CrewTimelineWarningCode::TimelineGap,
                        'remarks' => "Timeline gap from {$gapFrom} to {$gapTo}.",
                        'from_date' => $gapFrom,
                        'to_date' => $gapTo,
                    ];
                }
            }
        }

        return $gaps;
    }
}
