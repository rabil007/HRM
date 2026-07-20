<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CrewTimelineRangeBuilder
{
    /**
     * @param  list<array{
     *     employee_id: int,
     *     crew_assignment_id: int,
     *     crew_assignment_phase_id: int,
     *     phase_code: CrewPhaseCode,
     *     pay_category: CrewTimesheetPayCategory,
     *     date: string,
     *     source_actual_start_at: CarbonInterface|null,
     *     source_actual_end_at: CarbonInterface|null,
     *     overlapped: bool
     * }>  $allocatedDays
     * @return list<array{
     *     employee_id: int,
     *     crew_assignment_id: int,
     *     crew_assignment_phase_id: int,
     *     phase_code: CrewPhaseCode,
     *     pay_category: CrewTimesheetPayCategory,
     *     from_date: string,
     *     to_date: string,
     *     days: float,
     *     source_actual_start_at: CarbonInterface|null,
     *     source_actual_end_at: CarbonInterface|null,
     *     warning_code: CrewTimelineWarningCode|null,
     *     remarks: string|null
     * }>
     */
    public function build(array $allocatedDays): array
    {
        if ($allocatedDays === []) {
            return [];
        }

        $ranges = [];
        $current = null;

        foreach ($allocatedDays as $day) {
            $sameGroup = $current !== null
                && $current['employee_id'] === $day['employee_id']
                && $current['crew_assignment_id'] === $day['crew_assignment_id']
                && $current['crew_assignment_phase_id'] === $day['crew_assignment_phase_id']
                && $current['pay_category'] === $day['pay_category']
                && CarbonImmutable::parse($current['to_date'])->addDay()->toDateString() === $day['date'];

            if ($sameGroup) {
                $current['to_date'] = $day['date'];
                $current['days'] = (float) (
                    CarbonImmutable::parse($current['from_date'])->diffInDays(
                        CarbonImmutable::parse($current['to_date']),
                    ) + 1
                );
                $current['overlapped'] = $current['overlapped'] || $day['overlapped'];

                continue;
            }

            if ($current !== null) {
                $ranges[] = $this->finalize($current);
            }

            $current = [
                'employee_id' => $day['employee_id'],
                'crew_assignment_id' => $day['crew_assignment_id'],
                'crew_assignment_phase_id' => $day['crew_assignment_phase_id'],
                'phase_code' => $day['phase_code'],
                'pay_category' => $day['pay_category'],
                'from_date' => $day['date'],
                'to_date' => $day['date'],
                'days' => 1.0,
                'source_actual_start_at' => $day['source_actual_start_at'],
                'source_actual_end_at' => $day['source_actual_end_at'],
                'overlapped' => $day['overlapped'],
            ];
        }

        if ($current !== null) {
            $ranges[] = $this->finalize($current);
        }

        return $ranges;
    }

    /**
     * @param  array{
     *     employee_id: int,
     *     crew_assignment_id: int,
     *     crew_assignment_phase_id: int,
     *     phase_code: CrewPhaseCode,
     *     pay_category: CrewTimesheetPayCategory,
     *     from_date: string,
     *     to_date: string,
     *     days: float,
     *     source_actual_start_at: CarbonInterface|null,
     *     source_actual_end_at: CarbonInterface|null,
     *     overlapped: bool
     * }  $range
     * @return array{
     *     employee_id: int,
     *     crew_assignment_id: int,
     *     crew_assignment_phase_id: int,
     *     phase_code: CrewPhaseCode,
     *     pay_category: CrewTimesheetPayCategory,
     *     from_date: string,
     *     to_date: string,
     *     days: float,
     *     source_actual_start_at: CarbonInterface|null,
     *     source_actual_end_at: CarbonInterface|null,
     *     warning_code: CrewTimelineWarningCode|null,
     *     remarks: string|null
     * }
     */
    private function finalize(array $range): array
    {
        return [
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
            'warning_code' => $range['overlapped'] ? CrewTimelineWarningCode::OverlappingPhases : null,
            'remarks' => $range['overlapped']
                ? 'Multiple phases claimed this date range; higher-priority category was kept.'
                : null,
        ];
    }
}
