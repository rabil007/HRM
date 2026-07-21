<?php

namespace App\Support\Payroll\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpsertCrewTimesheet
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    private const OPERATIONAL_KEYS = [
        'standby_from',
        'standby_to',
        'standby_days',
        'sign_on_standby_from',
        'sign_on_standby_to',
        'sign_on_standby_days',
        'onsite_from',
        'onsite_to',
        'onsite_days',
        'sign_off_standby_from',
        'sign_off_standby_to',
        'sign_off_standby_days',
        'crew_timesheet_preparation_id',
        'operational_approved_by',
        'operational_approved_at',
        'movement_source_hash',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(PayrollPeriod $period, Employee $employee, array $data): CrewTimesheet
    {
        abort_unless((int) $period->company_id === (int) $employee->company_id, 404);

        return DB::transaction(function () use ($period, $employee, $data): CrewTimesheet {
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

            $existing = CrewTimesheet::query()
                ->where('company_id', $period->company_id)
                ->where('employee_id', $employee->id)
                ->where('period_id', $period->id)
                ->with('preparation')
                ->lockForUpdate()
                ->first();

            $source = $this->resolveSource($data, $existing);
            $isDaily = $contract->resolvedSalaryStructure() === ContractSalaryStructure::Daily;
            $isCrewOperationsMode = $period->crew_timesheet_mode === CrewTimesheetMode::CrewOperations;

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

            if ($isCrewOperationsMode && $isDaily) {
                if ($this->hasOperationalPayload($data)) {
                    throw ValidationException::withMessages([
                        'standby_days' => 'Daily crew operational days come from the Applied Crew Operations timeline and cannot be set manually or via import.',
                    ]);
                }

                return CrewTimesheet::query()->updateOrCreate(
                    [
                        'company_id' => $period->company_id,
                        'employee_id' => $employee->id,
                        'period_id' => $period->id,
                    ],
                    [
                        'overtime_hours' => $this->financialValue($data, $existing, 'overtime_hours', 0),
                        'overtime_amount' => $this->financialValue($data, $existing, 'overtime_amount', 0),
                        'additional_amount' => $this->financialValue($data, $existing, 'additional_amount', 0),
                        'deduction_amount' => $this->financialValue($data, $existing, 'deduction_amount', 0),
                        'remarks' => $this->financialValue($data, $existing, 'remarks', null),
                        'source' => $source === CrewTimesheetSource::Import
                            ? CrewTimesheetSource::Import
                            : CrewTimesheetSource::Manual,
                    ],
                );
            }

            return CrewTimesheet::query()->updateOrCreate(
                [
                    'company_id' => $period->company_id,
                    'employee_id' => $employee->id,
                    'period_id' => $period->id,
                ],
                [
                    'standby_from' => $data['standby_from'] ?? null,
                    'standby_to' => $data['standby_to'] ?? null,
                    'standby_days' => $data['standby_days'] ?? null,
                    'onsite_from' => $data['onsite_from'] ?? null,
                    'onsite_to' => $data['onsite_to'] ?? null,
                    'onsite_days' => $data['onsite_days'] ?? null,
                    'overtime_hours' => $this->financialValue($data, $existing, 'overtime_hours', 0),
                    'additional_amount' => $this->financialValue($data, $existing, 'additional_amount', 0),
                    'deduction_amount' => $this->financialValue($data, $existing, 'deduction_amount', 0),
                    'remarks' => $this->financialValue($data, $existing, 'remarks', null),
                    'source' => $source,
                ],
            );
        });
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
        foreach ([
            'standby_from',
            'standby_to',
            'standby_days',
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
    private function assertNoOperationalMutation(array $data, CrewTimesheet $existing): void
    {
        foreach (self::OPERATIONAL_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
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
