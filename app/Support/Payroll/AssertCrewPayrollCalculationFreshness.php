<?php

namespace App\Support\Payroll;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fingerprints Daily Crew calculation inputs so approve rejects stale snapshots.
 */
final class AssertCrewPayrollCalculationFreshness
{
    public function __construct(
        private readonly ResolveCrewContractForWorkDate $resolveContractForWorkDate,
        private readonly ResolveEffectiveContractSalaryComponents $resolveEffectiveComponents,
    ) {}

    /**
     * @param  Collection<int, CrewTimesheetSegment>|iterable<int, CrewTimesheetSegment>  $segments
     * @param  list<array<string, mixed>>  $allocationDays
     * @return array{
     *     segments: string,
     *     contracts_revisions: string,
     *     generated_at: string
     * }
     */
    public function fingerprint(
        CrewTimesheet $timesheet,
        iterable $segments,
        array $allocationDays = [],
    ): array {
        return [
            'segments' => $this->hashSegments($timesheet, $segments),
            'contracts_revisions' => $this->hashContractsRevisions($allocationDays),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Recompute fingerprints for crew records that have work allocations and
     * reject approve when source segments or historical rates drifted.
     */
    public function assertFreshForApprove(PayrollPeriod $period): void
    {
        if (! $period->isCrew()) {
            return;
        }

        $companyId = (int) $period->company_id;

        $records = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', (int) $period->id)
            ->whereNull('deleted_at')
            ->get();

        if ($records->isEmpty()) {
            return;
        }

        $recordIds = $records->pluck('id')->all();

        $allocations = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', (int) $period->id)
            ->whereIn('payroll_record_id', $recordIds !== [] ? $recordIds : [0])
            ->get()
            ->groupBy('payroll_record_id');

        if ($allocations->isEmpty()) {
            return;
        }

        $timesheetIds = $allocations
            ->flatten(1)
            ->pluck('crew_timesheet_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $timesheets = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $timesheetIds !== [] ? $timesheetIds : [0])
            ->with(['segments' => fn ($query) => $query->orderBy('sequence')->orderBy('id')])
            ->get()
            ->keyBy('id');

        foreach ($records as $record) {
            $recordAllocations = $allocations->get($record->id);

            if ($recordAllocations === null || $recordAllocations->isEmpty()) {
                continue;
            }

            $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
            $stored = is_array($breakdown['source_fingerprint'] ?? null)
                ? $breakdown['source_fingerprint']
                : null;

            if ($stored === null) {
                throw ValidationException::withMessages([
                    'period_id' => 'Payroll must be regenerated before approval because the calculation fingerprint is missing.',
                ]);
            }

            $timesheetId = $recordAllocations->pluck('crew_timesheet_id')->filter()->first();
            $timesheet = $timesheetId !== null ? $timesheets->get((int) $timesheetId) : null;

            if ($timesheet === null) {
                throw ValidationException::withMessages([
                    'period_id' => 'Payroll must be regenerated before approval because source timesheet data changed.',
                ]);
            }

            $allocationDays = $recordAllocations->map(fn (PayrollWorkAllocation $row): array => [
                'work_date' => CarbonImmutable::parse($row->work_date)->toDateString(),
                'contract_id' => (int) $row->contract_id,
                'salary_revision_id' => $row->salary_revision_id !== null ? (int) $row->salary_revision_id : null,
                'basic_daily_rate' => (float) $row->basic_daily_rate,
                'site_allowance_daily_rate' => (float) $row->site_allowance_daily_rate,
                'supplementary_allowance_daily_rate' => (float) $row->supplementary_allowance_daily_rate,
            ])->values()->all();

            // Re-resolve revision ids from current contract history for each work date.
            $resolvedDays = [];
            $contractsByEmployee = $this->resolveContractForWorkDate->loadContracts(
                $companyId,
                (int) $record->employee_id,
            );

            foreach ($allocationDays as $day) {
                $resolved = $this->resolveContractForWorkDate->resolve(
                    $companyId,
                    (int) $record->employee_id,
                    $day['work_date'],
                    $contractsByEmployee,
                );

                $revisionId = null;
                $basicRate = 0.0;
                $siteRate = 0.0;
                $suppRate = 0.0;

                if ($resolved['contract'] !== null) {
                    $revisionResult = $this->resolveContractForWorkDate->resolveSalaryRevision(
                        $resolved['contract'],
                        $day['work_date'],
                    );
                    $revisionId = $revisionResult['revision']?->id !== null
                        ? (int) $revisionResult['revision']->id
                        : null;

                    $components = $this->resolveEffectiveComponents->handle(
                        $resolved['contract'],
                        CarbonImmutable::parse($day['work_date']),
                    );

                    $basicRate = $this->activeAmount($components, SalaryComponentCode::Basic) ?? 0.0;
                    $siteRate = $this->activeAmount($components, SalaryComponentCode::SiteAllowance) ?? 0.0;
                    $suppRate = $this->activeAmount($components, SalaryComponentCode::SupplementaryAllowance) ?? 0.0;
                }

                $resolvedDays[] = [
                    'work_date' => $day['work_date'],
                    'contract_id' => $resolved['contract'] !== null ? (int) $resolved['contract']->id : null,
                    'salary_revision_id' => $revisionId,
                    'basic_daily_rate' => $basicRate,
                    'site_allowance_daily_rate' => $siteRate,
                    'supplementary_allowance_daily_rate' => $suppRate,
                ];
            }

            $current = $this->fingerprint($timesheet, $timesheet->segments, $resolvedDays);

            if (($stored['segments'] ?? null) !== $current['segments']
                || ($stored['contracts_revisions'] ?? null) !== $current['contracts_revisions']) {
                throw ValidationException::withMessages([
                    'period_id' => 'Payroll must be regenerated before approval because timesheet movements or contract rates changed since generation.',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, CrewTimesheetSegment>|iterable<int, CrewTimesheetSegment>  $segments
     */
    private function hashSegments(CrewTimesheet $timesheet, iterable $segments): string
    {
        $rows = [];

        foreach ($segments as $segment) {
            $rows[] = [
                'id' => (int) $segment->id,
                'company_id' => (int) ($segment->company_id ?? $timesheet->company_id),
                'from_date' => $segment->from_date?->toDateString() ?? (string) $segment->from_date,
                'to_date' => $segment->to_date?->toDateString() ?? (string) $segment->to_date,
                'pay_category' => $segment->pay_category?->value ?? (string) $segment->pay_category,
                'days' => (float) $segment->days,
                'updated_at' => optional($segment->updated_at)?->toIso8601String(),
            ];
        }

        usort($rows, fn (array $left, array $right): int => [$left['id']] <=> [$right['id']]);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array<string, mixed>>  $allocationDays
     */
    private function hashContractsRevisions(array $allocationDays): string
    {
        $rows = array_map(fn (array $day): array => [
            'work_date' => (string) ($day['work_date'] ?? ''),
            'contract_id' => $day['contract_id'] ?? null,
            'salary_revision_id' => $day['salary_revision_id'] ?? null,
            'basic_daily_rate' => round((float) ($day['basic_daily_rate'] ?? 0), 2),
            'site_allowance_daily_rate' => round((float) ($day['site_allowance_daily_rate'] ?? 0), 2),
            'supplementary_allowance_daily_rate' => round((float) ($day['supplementary_allowance_daily_rate'] ?? 0), 2),
        ], $allocationDays);

        usort($rows, fn (array $left, array $right): int => [
            $left['work_date'],
            $left['contract_id'],
            $left['salary_revision_id'],
        ] <=> [
            $right['work_date'],
            $right['contract_id'],
            $right['salary_revision_id'],
        ]);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function activeAmount(Collection $components, SalaryComponentCode $code): ?float
    {
        $component = $components->first(
            fn (ContractSalaryComponent $item) => $item->component_code === $code
                && $item->status === SalaryComponentStatus::Active,
        );

        if ($component === null || (float) $component->amount <= 0) {
            return null;
        }

        return (float) $component->amount;
    }
}
