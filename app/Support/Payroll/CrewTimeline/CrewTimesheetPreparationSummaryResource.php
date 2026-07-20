<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewTimelineWarningCode;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;

final class CrewTimesheetPreparationSummaryResource
{
    public function __construct(
        private readonly CrewTimelineFreshnessChecker $freshnessChecker,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     version: int,
     *     status: string,
     *     status_label: string,
     *     is_fresh: bool,
     *     is_stale: bool,
     *     blocking_warning_count: int,
     *     informational_warning_count: int,
     *     prepared_at: string|null,
     *     submitted_at: string|null,
     *     approved_at: string|null,
     *     returned_at: string|null
     * }|null
     */
    public function toArray(
        ?CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): ?array {
        if ($preparation === null) {
            return null;
        }

        $blocking = 0;
        $informational = 0;

        foreach ($preparation->lines as $line) {
            $code = CrewTimelineWarningCode::tryFrom((string) ($line->warning_code ?? ''));

            if ($code === null) {
                continue;
            }

            if ($code->isBlocking()) {
                $blocking++;
            } else {
                $informational++;
            }
        }

        $isFresh = $this->freshnessChecker->isFresh($preparation, $period);

        return [
            'id' => $preparation->id,
            'version' => $preparation->version,
            'status' => $preparation->status->value,
            'status_label' => $preparation->status->label(),
            'is_fresh' => $isFresh,
            'is_stale' => ! $isFresh,
            'blocking_warning_count' => $blocking,
            'informational_warning_count' => $informational,
            'prepared_at' => $preparation->prepared_at?->toIso8601String(),
            'submitted_at' => $preparation->submitted_at?->toIso8601String(),
            'approved_at' => $preparation->approved_at?->toIso8601String(),
            'returned_at' => $preparation->returned_at?->toIso8601String(),
        ];
    }
}
