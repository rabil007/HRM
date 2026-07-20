<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewTimelineDayAllocator
{
    public function __construct(
        private readonly CrewPhasePayCategoryResolver $categoryResolver,
    ) {}

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<array{
     *     employee_id: int,
     *     crew_assignment_id: int,
     *     crew_assignment_phase_id: int,
     *     phase_code: CrewPhaseCode,
     *     pay_category: CrewTimesheetPayCategory,
     *     date: string,
     *     source_actual_start_at: CarbonInterface|null,
     *     source_actual_end_at: CarbonInterface|null,
     *     overlapped: bool
     * }>
     */
    public function allocate(
        PayrollPeriod $period,
        Collection $phases,
        CarbonInterface $effectiveEnd,
        int $companyId,
    ): array {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $periodStart = CarbonImmutable::parse($period->start_date->toDateString(), $timezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($effectiveEnd->toDateString(), $timezone)->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            return [];
        }

        $employeeIds = $phases
            ->map(fn (CrewAssignmentPhase $phase) => (int) $phase->assignment?->employee_id)
            ->filter()
            ->unique()
            ->values();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->with('currentContract')
            ->get()
            ->keyBy('id');

        /** @var array<string, list<array<string, mixed>>> $claimsByDay */
        $claimsByDay = [];

        foreach ($phases as $phase) {
            $assignment = $phase->assignment;
            $employeeId = (int) ($assignment?->employee_id ?? 0);

            if (
                $assignment === null
                || $employeeId < 1
                || (int) $phase->company_id !== $companyId
                || (int) $assignment->company_id !== $companyId
                || $phase->actual_start_at === null
            ) {
                continue;
            }

            if (
                $phase->actual_end_at !== null
                && $phase->actual_end_at->lt($phase->actual_start_at)
            ) {
                continue;
            }

            $employee = $employees->get($employeeId);
            $contract = $employee?->currentContract;

            if (
                $contract === null
                || $contract->payroll_category !== PayrollCategory::Crew
                || $contract->resolvedSalaryStructure() === ContractSalaryStructure::Monthly
            ) {
                continue;
            }

            $range = $this->phaseDateRange($phase, $periodStart, $periodEnd, $timezone);

            if ($range === null) {
                continue;
            }

            [$from, $to] = $range;
            $category = $this->categoryResolver->resolve($phase->phase_code);

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $key = $employeeId.'|'.$day->toDateString();
                $claimsByDay[$key][] = [
                    'employee_id' => $employeeId,
                    'crew_assignment_id' => (int) $phase->crew_assignment_id,
                    'crew_assignment_phase_id' => (int) $phase->id,
                    'phase_code' => $phase->phase_code,
                    'pay_category' => $category,
                    'date' => $day->toDateString(),
                    'source_actual_start_at' => $phase->actual_start_at,
                    'source_actual_end_at' => $phase->actual_end_at,
                    'priority' => $this->categoryResolver->priority($category),
                ];
            }
        }

        $allocated = [];

        foreach ($claimsByDay as $claims) {
            usort($claims, function (array $left, array $right): int {
                $priority = $right['priority'] <=> $left['priority'];

                if ($priority !== 0) {
                    return $priority;
                }

                return $left['crew_assignment_phase_id'] <=> $right['crew_assignment_phase_id'];
            });

            $winner = $claims[0];
            $overlapped = count($claims) > 1;

            $allocated[] = [
                'employee_id' => $winner['employee_id'],
                'crew_assignment_id' => $winner['crew_assignment_id'],
                'crew_assignment_phase_id' => $winner['crew_assignment_phase_id'],
                'phase_code' => $winner['phase_code'],
                'pay_category' => $winner['pay_category'],
                'date' => $winner['date'],
                'source_actual_start_at' => $winner['source_actual_start_at'],
                'source_actual_end_at' => $winner['source_actual_end_at'],
                'overlapped' => $overlapped,
            ];
        }

        usort($allocated, function (array $left, array $right): int {
            return [$left['employee_id'], $left['date'], $left['crew_assignment_phase_id']]
                <=> [$right['employee_id'], $right['date'], $right['crew_assignment_phase_id']];
        });

        return $allocated;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function phaseDateRange(
        CrewAssignmentPhase $phase,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        string $timezone,
    ): ?array {
        $start = CarbonImmutable::parse(
            $phase->actual_start_at->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        if ($phase->actual_end_at !== null) {
            $end = CarbonImmutable::parse(
                $phase->actual_end_at->timezone($timezone)->toDateString(),
                $timezone,
            )->startOfDay();
        } elseif ($phase->status === CrewPhaseStatus::Active) {
            $end = $periodEnd;
        } else {
            return null;
        }

        if ($start->gt($periodEnd) || $end->lt($periodStart)) {
            return null;
        }

        $from = $start->lt($periodStart) ? $periodStart : $start;
        $to = $end->gt($periodEnd) ? $periodEnd : $end;

        if ($from->gt($to)) {
            return null;
        }

        return [$from, $to];
    }
}
