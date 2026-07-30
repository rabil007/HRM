<?php

namespace App\Support\Payroll\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Payroll\ApplyManualImportTimesheetAutoApproval;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Payroll\SyncCrewTimesheetParentFromSegments;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpsertCrewTimesheet
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly ApplyManualImportTimesheetAutoApproval $autoApproval,
        private readonly SyncCrewTimesheetParentFromSegments $syncParentFromSegments,
    ) {}

    private const OPERATIONAL_KEYS = [
        'sign_on_standby_from',
        'sign_on_standby_to',
        'sign_on_standby_days',
        'onsite_from',
        'onsite_to',
        'onsite_days',
        'sign_off_standby_from',
        'sign_off_standby_to',
        'sign_off_standby_days',
        'segments',
        'crew_timesheet_preparation_id',
        'operational_approved_by',
        'operational_approved_at',
        'movement_source_hash',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        PayrollPeriod $period,
        Employee $employee,
        array $data,
        ?int $approvedByUserId = null,
    ): CrewTimesheet {
        abort_unless((int) $period->company_id === (int) $employee->company_id, 404);

        return DB::transaction(function () use ($period, $employee, $data, $approvedByUserId): CrewTimesheet {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $period->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $period->isEditable()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Timesheets can only be edited for draft payroll periods.',
                ]);
            }

            if (! $period->isCrew()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Crew timesheets can only be saved on crew pay periods.',
                ]);
            }

            $contract = $this->resolveContract->resolve($employee, $period);

            if ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Only employees with an active crew contract can have crew timesheets.',
                ]);
            }

            $existing = CrewTimesheet::withTrashed()
                ->where('company_id', $period->company_id)
                ->where('employee_id', $employee->id)
                ->where('period_id', $period->id)
                ->with('preparation')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->trashed()) {
                $existing->restore();
                $existing->setRelation('preparation', null);
            }

            $source = $this->resolveSource($data, $existing);
            $approvedByUserId = $approvedByUserId ?? auth()->id();

            if ($this->autoApproval->shouldAutoApprove($source) && $approvedByUserId === null) {
                throw ValidationException::withMessages([
                    'employee_id' => 'An authenticated user is required to save manual or imported crew timesheets.',
                ]);
            }

            $isDaily = $contract->resolvedSalaryStructure() === ContractSalaryStructure::Daily;
            $exclusiveCrewOperations = $period->requiresExclusiveCrewOperationsTimesheets();

            if ($existing !== null && $existing->isOperationallyLocked()) {
                $this->assertNoOperationalMutation($data, $existing);

                $existing->fill([
                    'overtime_hours' => $this->financialValue($data, $existing, 'overtime_hours', 0),
                    'overtime_amount' => $this->financialValue($data, $existing, 'overtime_amount', 0),
                    'additional_amount' => $this->financialValue($data, $existing, 'additional_amount', 0),
                    'deduction_amount' => $this->financialValue($data, $existing, 'deduction_amount', 0),
                    'remarks' => $this->financialValue($data, $existing, 'remarks', null),
                ]);
                $existing->save();

                return $existing->fresh() ?? $existing;
            }

            if ($exclusiveCrewOperations && $isDaily) {
                if ($this->hasOperationalPayload($data)) {
                    throw ValidationException::withMessages([
                        'sign_on_standby_days' => 'Daily crew operational days come from the Applied Crew Operations timeline and cannot be set manually or via import.',
                    ]);
                }

                $attributes = array_merge(
                    [
                        'overtime_hours' => $this->financialValue($data, $existing, 'overtime_hours', 0),
                        'overtime_amount' => $this->financialValue($data, $existing, 'overtime_amount', 0),
                        'additional_amount' => $this->financialValue($data, $existing, 'additional_amount', 0),
                        'deduction_amount' => $this->financialValue($data, $existing, 'deduction_amount', 0),
                        'remarks' => $this->financialValue($data, $existing, 'remarks', null),
                        'source' => $source === CrewTimesheetSource::Import
                            ? CrewTimesheetSource::Import
                            : CrewTimesheetSource::Manual,
                        'crew_timesheet_preparation_id' => null,
                        'operational_approved_by' => null,
                        'operational_approved_at' => null,
                        'movement_source_hash' => null,
                    ],
                    $this->autoApproval->shouldAutoApprove($source)
                        ? $this->autoApproval->approvalAttributes($approvedByUserId)
                        : [
                            'approval_status' => $existing?->approval_status ?? CrewTimesheetApprovalStatus::Draft,
                        ],
                );

                return $this->persistTimesheet($period, $employee, $existing, $attributes);
            }

            $replaceSegments = $this->shouldReplaceSegments($data);

            if (! $replaceSegments) {
                $attributes = [
                    'unpaid_leave_days' => array_key_exists('unpaid_leave_days', $data)
                        ? $data['unpaid_leave_days']
                        : ($existing?->unpaid_leave_days),
                    'overtime_hours' => $this->financialValue($data, $existing, 'overtime_hours', 0),
                    'additional_amount' => $this->financialValue($data, $existing, 'additional_amount', 0),
                    'deduction_amount' => $this->financialValue($data, $existing, 'deduction_amount', 0),
                    'remarks' => $this->financialValue($data, $existing, 'remarks', null),
                    'source' => $existing?->source ?? $source,
                ];

                if ($this->autoApproval->shouldAutoApprove($source)) {
                    $attributes = array_merge(
                        $attributes,
                        $this->autoApproval->approvalAttributes($approvedByUserId),
                    );
                }

                return $this->persistTimesheet($period, $employee, $existing, $attributes);
            }

            $attributes = [
                'sign_on_standby_from' => $data['sign_on_standby_from'] ?? null,
                'sign_on_standby_to' => $data['sign_on_standby_to'] ?? null,
                'sign_on_standby_days' => $data['sign_on_standby_days'] ?? null,
                'onsite_from' => $data['onsite_from'] ?? null,
                'onsite_to' => $data['onsite_to'] ?? null,
                'onsite_days' => $data['onsite_days'] ?? null,
                'sign_off_standby_from' => $data['sign_off_standby_from'] ?? null,
                'sign_off_standby_to' => $data['sign_off_standby_to'] ?? null,
                'sign_off_standby_days' => $data['sign_off_standby_days'] ?? null,
                'unpaid_leave_days' => $data['unpaid_leave_days'] ?? null,
                'overtime_hours' => $this->financialValue($data, $existing, 'overtime_hours', 0),
                'additional_amount' => $this->financialValue($data, $existing, 'additional_amount', 0),
                'deduction_amount' => $this->financialValue($data, $existing, 'deduction_amount', 0),
                'remarks' => $this->financialValue($data, $existing, 'remarks', null),
                'source' => $source,
                'crew_timesheet_preparation_id' => null,
                'operational_approved_by' => null,
                'operational_approved_at' => null,
                'movement_source_hash' => null,
            ];

            if ($this->autoApproval->shouldAutoApprove($source)) {
                $attributes = array_merge(
                    $attributes,
                    $this->autoApproval->approvalAttributes($approvedByUserId),
                );
            }

            $timesheet = $this->persistTimesheet($period, $employee, $existing, $attributes);

            if ($isDaily) {
                $this->syncManualImportSegments($timesheet, $data, $source);
                $timesheet = $this->syncParentFromSegments->handle($timesheet->fresh() ?? $timesheet);
            }

            return $timesheet;
        });
    }

    /**
     * Only replace Manual/Import segments when the caller intentionally submitted
     * a non-empty segments list or filled flat operational date fields.
     * Financial-only updates (and blank import rows) must leave segments untouched.
     *
     * @param  array<string, mixed>  $data
     */
    private function shouldReplaceSegments(array $data): bool
    {
        if (array_key_exists('segments', $data) && is_array($data['segments']) && $data['segments'] !== []) {
            return true;
        }

        foreach ([
            'sign_on_standby_from',
            'sign_on_standby_to',
            'sign_on_standby_days',
            'onsite_from',
            'onsite_to',
            'onsite_days',
            'sign_off_standby_from',
            'sign_off_standby_to',
            'sign_off_standby_days',
        ] as $key) {
            if (array_key_exists($key, $data) && filled($data[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncManualImportSegments(
        CrewTimesheet $timesheet,
        array $data,
        CrewTimesheetSource $source,
    ): void {
        $segmentRows = $this->normalizeSegmentRows($data);

        $existingQuery = CrewTimesheetSegment::query()
            ->where('company_id', $timesheet->company_id)
            ->where('crew_timesheet_id', $timesheet->id)
            ->whereIn('source', [
                CrewTimesheetSource::Manual->value,
                CrewTimesheetSource::Import->value,
            ]);

        if ($segmentRows === []) {
            if ((clone $existingQuery)->exists()) {
                throw ValidationException::withMessages([
                    'segments' => 'At least one valid movement period is required when updating operational dates.',
                ]);
            }

            return;
        }

        $existingQuery
            ->lockForUpdate()
            ->get()
            ->each
            ->delete();

        $sequence = 1;

        foreach ($segmentRows as $row) {
            CrewTimesheetSegment::query()->create([
                'company_id' => $timesheet->company_id,
                'crew_timesheet_id' => $timesheet->id,
                'sequence' => $sequence++,
                'pay_category' => $row['pay_category'],
                'from_date' => $row['from_date'],
                'to_date' => $row['to_date'],
                'days' => $row['days'],
                'source' => $source,
                'vessel_id' => $row['vessel_id'] ?? null,
                'client_id' => $row['client_id'] ?? null,
                'rank_id' => $row['rank_id'] ?? null,
                'remarks' => $row['remarks'] ?? null,
            ]);
        }
    }

    /**
     * Accept either an explicit segments array or the legacy flat category ranges.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeSegmentRows(array $data): array
    {
        if (isset($data['segments']) && is_array($data['segments']) && $data['segments'] !== []) {
            $rows = [];

            foreach ($data['segments'] as $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                $from = $segment['from_date'] ?? null;
                $to = $segment['to_date'] ?? null;
                $category = $segment['pay_category'] ?? null;

                if ($from === null || $to === null || $category === null) {
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

        $rows = [];

        foreach ([
            CrewTimesheetPayCategory::SignOnStandby->value => ['sign_on_standby_from', 'sign_on_standby_to', 'sign_on_standby_days'],
            CrewTimesheetPayCategory::Onsite->value => ['onsite_from', 'onsite_to', 'onsite_days'],
            CrewTimesheetPayCategory::SignOffStandby->value => ['sign_off_standby_from', 'sign_off_standby_to', 'sign_off_standby_days'],
        ] as $category => [$fromKey, $toKey, $daysKey]) {
            $from = $data[$fromKey] ?? null;
            $to = $data[$toKey] ?? null;

            if ($from === null || $to === null || $from === '' || $to === '') {
                continue;
            }

            $rows[] = [
                'pay_category' => $category,
                'from_date' => $from,
                'to_date' => $to,
                'days' => $data[$daysKey] ?? $this->inclusiveDays(
                    is_string($from) ? $from : null,
                    is_string($to) ? $to : null,
                ),
                'vessel_id' => null,
                'client_id' => null,
                'rank_id' => null,
                'remarks' => null,
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

    /**
     * Persist against an active or restored soft-deleted row to honour the
     * unique (company_id, employee_id, period_id) constraint.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistTimesheet(
        PayrollPeriod $period,
        Employee $employee,
        ?CrewTimesheet $existing,
        array $attributes,
    ): CrewTimesheet {
        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();

            return $existing->fresh() ?? $existing;
        }

        return CrewTimesheet::query()->create(array_merge([
            'company_id' => $period->company_id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
        ], $attributes));
    }

    /**
     * Explicit-presence merge: an absent key preserves the existing value, an
     * explicit value (including zero) overwrites it.
     *
     * @param  array<string, mixed>  $data
     */
    private function financialValue(array $data, ?CrewTimesheet $existing, string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $data) && $data[$key] !== null) {
            return $data[$key];
        }

        if ($existing !== null) {
            return $existing->getAttribute($key) ?? $default;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSource(array $data, ?CrewTimesheet $existing): CrewTimesheetSource
    {
        if ($existing !== null && $existing->isOperationallyLocked()) {
            return CrewTimesheetSource::CrewOperations;
        }

        if (($data['source'] ?? null) instanceof CrewTimesheetSource) {
            return $data['source'];
        }

        if (is_string($data['source'] ?? null)) {
            return CrewTimesheetSource::tryFrom($data['source']) ?? CrewTimesheetSource::Manual;
        }

        return CrewTimesheetSource::Manual;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasOperationalPayload(array $data): bool
    {
        if (isset($data['segments']) && is_array($data['segments'])) {
            foreach ($data['segments'] as $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                if (filled($segment['from_date'] ?? null) && filled($segment['to_date'] ?? null)) {
                    return true;
                }
            }
        }

        foreach ([
            'sign_on_standby_from',
            'sign_on_standby_to',
            'sign_on_standby_days',
            'onsite_from',
            'onsite_to',
            'onsite_days',
            'sign_off_standby_from',
            'sign_off_standby_to',
            'sign_off_standby_days',
        ] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value) && (float) $value == 0.0) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function operationalValuesChanged(array $data, CrewTimesheet $existing): bool
    {
        foreach ([
            'sign_on_standby_from',
            'sign_on_standby_to',
            'sign_on_standby_days',
            'onsite_from',
            'onsite_to',
            'onsite_days',
            'sign_off_standby_from',
            'sign_off_standby_to',
            'sign_off_standby_days',
            'unpaid_leave_days',
        ] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $incoming = $data[$key];
            $current = $existing->getAttribute($key);

            if ($current instanceof CarbonInterface) {
                $current = $current->toDateString();
            }

            if ($incoming instanceof CarbonInterface) {
                $incoming = $incoming->toDateString();
            }

            if ((string) ($incoming ?? '') !== (string) ($current ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function financialValuesChanged(array $data, CrewTimesheet $existing): bool
    {
        foreach (['overtime_hours', 'additional_amount', 'deduction_amount'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            if ((string) $data[$key] !== (string) ($existing->getAttribute($key) ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoOperationalMutation(array $data, CrewTimesheet $existing): void
    {
        if (isset($data['segments']) && is_array($data['segments']) && $data['segments'] !== []) {
            throw ValidationException::withMessages([
                'segments' => 'Operational Crew Operations timesheet fields cannot be changed after the timeline is Applied.',
            ]);
        }

        foreach (self::OPERATIONAL_KEYS as $key) {
            if ($key === 'segments' || ! array_key_exists($key, $data)) {
                continue;
            }

            $incoming = $data[$key];
            $current = $existing->getAttribute($key);

            if ($incoming instanceof \BackedEnum) {
                $incoming = $incoming->value;
            }

            if ($current instanceof \BackedEnum) {
                $current = $current->value;
            }

            if ($current instanceof CarbonInterface) {
                $current = $current->toDateString();
            }

            if ($incoming !== $current && ! ($incoming === null && $current === null)) {
                if ((string) $incoming === (string) $current) {
                    continue;
                }

                throw ValidationException::withMessages([
                    $key => 'Operational Crew Operations timesheet fields cannot be changed after the timeline is Applied.',
                ]);
            }
        }
    }
}
