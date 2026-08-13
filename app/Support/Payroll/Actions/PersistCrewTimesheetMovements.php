<?php

namespace App\Support\Payroll\Actions;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Support\Attendance\CalculateLeaveRequestDays;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes Manual/Import segments and recreates them as original ranges.
 * Ranges may start before the payroll period; days after period end are rejected upstream.
 */
final class PersistCrewTimesheetMovements
{
    /**
     * @param  list<array<string, mixed>>  $ranges
     * @return array{
     *     segment_count: int,
     *     categories: list<string>,
     *     previous_segment_count: int,
     *     previous_categories: list<string>
     * }
     */
    public function handle(
        CrewTimesheet $timesheet,
        PayrollPeriod $period,
        array $ranges,
        CrewTimesheetSource $source,
        ?int $actorId = null,
    ): array {
        if (! in_array($source, [CrewTimesheetSource::Manual, CrewTimesheetSource::Import], true)) {
            throw ValidationException::withMessages([
                'segments' => 'Only Manual or Import movement sources can be replaced this way.',
            ]);
        }

        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        if ($periodStart === null || $periodEnd === null) {
            throw ValidationException::withMessages([
                'period_id' => 'The payroll period must have a start and end date.',
            ]);
        }

        $normalized = $this->normalizeRanges($ranges);

        $existingSegments = CrewTimesheetSegment::query()
            ->where('company_id', $timesheet->company_id)
            ->where('crew_timesheet_id', $timesheet->id)
            ->whereIn('source', [
                CrewTimesheetSource::Manual->value,
                CrewTimesheetSource::Import->value,
            ])
            ->lockForUpdate()
            ->orderBy('sequence')
            ->get();

        $previousCategories = $existingSegments
            ->pluck('pay_category')
            ->map(fn ($category) => $category instanceof CrewTimesheetPayCategory ? $category->value : (string) $category)
            ->unique()
            ->values()
            ->all();

        $previousSegmentCount = $existingSegments->count();
        $existingSegments->each->delete();

        if ($normalized === []) {
            return [
                'segment_count' => 0,
                'categories' => [],
                'previous_segment_count' => $previousSegmentCount,
                'previous_categories' => $previousCategories,
            ];
        }

        $segmentSequence = 1;
        $createdSegments = 0;
        $categories = [];

        foreach ($normalized as $row) {
            $categories[] = (string) $row['pay_category'];

            CrewTimesheetSegment::query()->create([
                'company_id' => $timesheet->company_id,
                'crew_timesheet_id' => $timesheet->id,
                'sequence' => $segmentSequence++,
                'pay_category' => $row['pay_category'],
                'from_date' => $row['from_date'],
                'to_date' => $row['to_date'],
                'days' => $row['days'] ?? $this->inclusiveDays(
                    is_string($row['from_date']) ? $row['from_date'] : null,
                    is_string($row['to_date']) ? $row['to_date'] : null,
                ),
                'source' => $source,
                'vessel_id' => $row['vessel_id'],
                'client_id' => $row['client_id'],
                'rank_id' => $row['rank_id'],
                'remarks' => $row['remarks'],
            ]);
            $createdSegments++;
        }

        if ($createdSegments === 0) {
            throw ValidationException::withMessages([
                'segments' => 'At least one valid movement period is required within or before the payroll period.',
            ]);
        }

        return [
            'segment_count' => $createdSegments,
            'categories' => array_values(array_unique($categories)),
            'previous_segment_count' => $previousSegmentCount,
            'previous_categories' => $previousCategories,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ranges
     * @return list<array<string, mixed>>
     */
    private function normalizeRanges(array $ranges): array
    {
        $rows = [];

        foreach ($ranges as $range) {
            if (! is_array($range)) {
                continue;
            }

            $from = $range['from_date'] ?? null;
            $to = $range['to_date'] ?? null;
            $category = $range['pay_category'] ?? null;

            if ($from === null || $to === null || $category === null || $from === '' || $to === '') {
                continue;
            }

            $rows[] = [
                'pay_category' => $category,
                'from_date' => $from,
                'to_date' => $to,
                'days' => $range['days'] ?? $this->inclusiveDays(
                    is_string($from) ? $from : null,
                    is_string($to) ? $to : null,
                ),
                'vessel_id' => $range['vessel_id'] ?? null,
                'client_id' => $range['client_id'] ?? null,
                'rank_id' => $range['rank_id'] ?? null,
                'remarks' => $range['remarks'] ?? null,
                'source_excel_row' => $range['source_excel_row'] ?? null,
                'source_reference' => $range['source_reference'] ?? null,
            ];
        }

        return $rows;
    }

    private function inclusiveDays(?string $from, ?string $to): ?float
    {
        if (! filled($from) || ! filled($to)) {
            return null;
        }

        return round((new CalculateLeaveRequestDays)($from, $to), 2);
    }
}
