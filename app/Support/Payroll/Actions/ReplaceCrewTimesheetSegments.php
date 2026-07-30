<?php

namespace App\Support\Payroll\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Payroll\ApplyManualImportTimesheetAutoApproval;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Payroll\SyncCrewTimesheetParentFromSegments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReplaceCrewTimesheetSegments
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly ApplyManualImportTimesheetAutoApproval $autoApproval,
        private readonly SyncCrewTimesheetParentFromSegments $syncParentFromSegments,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public function handle(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        array $segments,
        User $actor,
        int $companyId,
    ): CrewTimesheet {
        return DB::transaction(function () use ($period, $timesheet, $segments, $actor, $companyId): CrewTimesheet {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $period->isEditable()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Timesheets can only be edited for draft payroll periods.',
                ]);
            }

            if (($period->payroll_category ?? PayrollCategory::Crew) !== PayrollCategory::Crew) {
                throw ValidationException::withMessages([
                    'period_id' => 'Crew timesheets can only be saved on crew pay periods.',
                ]);
            }

            $timesheet = CrewTimesheet::query()
                ->whereKey($timesheet->id)
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->with(['preparation', 'employee'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($timesheet->isOperationallyLocked()) {
                throw ValidationException::withMessages([
                    'segments' => 'Operational Crew Operations timesheet fields cannot be changed after the timeline is Applied.',
                ]);
            }

            $employee = $timesheet->employee;

            if ($employee === null || (int) $employee->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The timesheet employee was not found for this company.',
                ]);
            }

            $contract = $this->resolveContract->resolve($employee, $period);

            if ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Only employees with an active crew contract can have crew timesheets.',
                ]);
            }

            if ($contract->resolvedSalaryStructure() !== ContractSalaryStructure::Daily) {
                throw ValidationException::withMessages([
                    'segments' => 'Movement periods are only available for Daily Crew timesheets.',
                ]);
            }

            if ($period->requiresExclusiveCrewOperationsTimesheets()) {
                throw ValidationException::withMessages([
                    'segments' => 'Daily crew operational days come from the Applied Crew Operations timeline and cannot be set manually.',
                ]);
            }

            if ($segments === []) {
                throw ValidationException::withMessages([
                    'segments' => 'At least one movement period is required.',
                ]);
            }

            $normalized = $this->normalizeSegments($segments);

            if ($normalized === []) {
                throw ValidationException::withMessages([
                    'segments' => 'At least one valid movement period is required.',
                ]);
            }

            $existingSegments = CrewTimesheetSegment::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_id', $timesheet->id)
                ->whereIn('source', [
                    CrewTimesheetSource::Manual->value,
                    CrewTimesheetSource::Import->value,
                ])
                ->lockForUpdate()
                ->orderBy('sequence')
                ->get();

            $previousCount = $existingSegments->count();
            $previousCategories = $existingSegments
                ->pluck('pay_category')
                ->map(fn ($category) => $category instanceof CrewTimesheetPayCategory ? $category->value : (string) $category)
                ->unique()
                ->values()
                ->all();

            $existingSegments->each->delete();

            $sequence = 1;
            $source = CrewTimesheetSource::Manual;

            foreach ($normalized as $row) {
                CrewTimesheetSegment::query()->create([
                    'company_id' => $companyId,
                    'crew_timesheet_id' => $timesheet->id,
                    'sequence' => $sequence++,
                    'pay_category' => $row['pay_category'],
                    'from_date' => $row['from_date'],
                    'to_date' => $row['to_date'],
                    'days' => $row['days'],
                    'source' => $source,
                    'vessel_id' => $row['vessel_id'],
                    'client_id' => $row['client_id'],
                    'rank_id' => $row['rank_id'],
                    'remarks' => $row['remarks'],
                ]);
            }

            $attributes = [
                'source' => $source,
                'crew_timesheet_preparation_id' => null,
                'operational_approved_by' => null,
                'operational_approved_at' => null,
                'movement_source_hash' => null,
            ];

            if ($this->autoApproval->shouldAutoApprove($source)) {
                $attributes = array_merge(
                    $attributes,
                    $this->autoApproval->approvalAttributes((int) $actor->id),
                );
            }

            $timesheet->fill($attributes);
            $timesheet->save();

            $synced = $this->syncParentFromSegments->handle($timesheet->fresh(['segments']) ?? $timesheet);

            $newCategories = collect($normalized)
                ->pluck('pay_category')
                ->unique()
                ->values()
                ->all();

            activity()
                ->performedOn($synced)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timesheet_segments_replaced',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'crew_timesheet_id' => $synced->id,
                    'employee_id' => $synced->employee_id,
                    'previous_segment_count' => $previousCount,
                    'new_segment_count' => count($normalized),
                    'previous_categories' => $previousCategories,
                    'new_categories' => $newCategories,
                ])
                ->log('Crew timesheet movement periods replaced');

            return $synced;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function normalizeSegments(array $segments): array
    {
        $rows = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $from = $segment['from_date'] ?? null;
            $to = $segment['to_date'] ?? null;
            $category = $segment['pay_category'] ?? null;

            if ($from === null || $to === null || $category === null || $from === '' || $to === '') {
                continue;
            }

            $rows[] = [
                'pay_category' => $category,
                'from_date' => $from,
                'to_date' => $to,
                'days' => $segment['days'] ?? $this->inclusiveDays(
                    is_string($from) ? $from : null,
                    is_string($to) ? $to : null,
                ),
                'vessel_id' => $segment['vessel_id'] ?? null,
                'client_id' => $segment['client_id'] ?? null,
                'rank_id' => $segment['rank_id'] ?? null,
                'remarks' => $segment['remarks'] ?? null,
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
