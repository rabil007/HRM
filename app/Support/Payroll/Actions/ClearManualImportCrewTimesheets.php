<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\ClearableManualImportCrewTimesheetsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClearManualImportCrewTimesheets
{
    public function __construct(
        private readonly ClearableManualImportCrewTimesheetsQuery $clearableQuery,
    ) {}

    /**
     * Soft-delete every Manual / Import timesheet (and its segments) in a Draft crew period.
     *
     * @return array{cleared_count: int, cleared_timesheet_ids: list<int>}
     */
    public function handle(PayrollPeriod $period, User $actor, int $companyId): array
    {
        return DB::transaction(function () use ($period, $actor, $companyId): array {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (($period->payroll_category ?? PayrollCategory::Crew) !== PayrollCategory::Crew) {
                throw ValidationException::withMessages([
                    'period_id' => 'Clear Timesheets is only available for crew payroll periods.',
                ]);
            }

            if ($period->status !== PayrollPeriodStatus::Draft) {
                throw ValidationException::withMessages([
                    'period_id' => 'Timesheets can only be cleared while the pay period is draft.',
                ]);
            }

            $timesheets = $this->clearableQuery
                ->forPeriod($period, $companyId)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $clearedIds = [];

            foreach ($timesheets as $timesheet) {
                if (! $this->clearableQuery->isClearable($timesheet)) {
                    continue;
                }

                CrewTimesheetSegment::query()
                    ->where('company_id', $companyId)
                    ->where('crew_timesheet_id', $timesheet->id)
                    ->whereIn('source', ['manual', 'import'])
                    ->lockForUpdate()
                    ->get()
                    ->each
                    ->delete();

                $timesheet->delete();
                $clearedIds[] = (int) $timesheet->id;
            }

            activity()
                ->performedOn($period)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timesheets_cleared',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'cleared_count' => count($clearedIds),
                    'sources_cleared' => ['manual', 'import'],
                    'cleared_timesheet_ids' => $clearedIds,
                ])
                ->log('Manual/Imported crew timesheets cleared');

            return [
                'cleared_count' => count($clearedIds),
                'cleared_timesheet_ids' => $clearedIds,
            ];
        });
    }
}
