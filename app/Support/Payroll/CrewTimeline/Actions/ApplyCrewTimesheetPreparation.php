<?php

namespace App\Support\Payroll\CrewTimeline\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\ApplyCrewTimesheetPreparationResult;
use App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationWorkflowGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyCrewTimesheetPreparation
{
    public function __construct(
        private readonly CrewTimesheetPreparationWorkflowGuard $guard,
        private readonly CrewTimelineFreshnessChecker $freshnessChecker,
    ) {}

    public function handle(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        User $actor,
        int $companyId,
    ): ApplyCrewTimesheetPreparationResult {
        return DB::transaction(function () use ($period, $preparation, $actor, $companyId): ApplyCrewTimesheetPreparationResult {
            $period = PayrollPeriod::query()
                ->whereKey($period->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->where('payroll_period_id', $period->id)
                ->lockForUpdate()
                ->get();

            $preparation = CrewTimesheetPreparation::query()
                ->whereKey($preparation->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard->assertTenantOwnership($period, $preparation, $companyId);
            $this->guard->assertCrewDraftPeriod($period);

            if ($preparation->status === CrewTimesheetPreparationStatus::Applied) {
                return $this->idempotentResult($preparation, $period, $companyId);
            }

            $this->guard->assertStatus(
                $preparation,
                CrewTimesheetPreparationStatus::Approved,
                'Only approved preparations can be applied to timesheets.',
            );

            $this->freshnessChecker->assertFresh(
                $preparation,
                $period,
                CrewTimelineFreshnessChecker::APPLY_STALE_MESSAGE,
            );
            $this->guard->assertNoBlockingWarnings($preparation);
            $this->guard->assertNoAppliedPreparation($preparation);

            $lines = CrewTimesheetPreparationLine::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->with(['employee:id,employee_no,name,company_id'])
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                if ((int) $line->company_id !== $companyId) {
                    abort(404);
                }

                if ($line->employee !== null && (int) $line->employee->company_id !== $companyId) {
                    abort(404);
                }
            }

            $aggregates = $this->aggregatePayableLines($lines);
            $employeeIds = array_keys($aggregates);

            $existingTimesheets = CrewTimesheet::query()
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->whereIn('employee_id', $employeeIds !== [] ? $employeeIds : [0])
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');

            $employees = Employee::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $employeeIds !== [] ? $employeeIds : [0])
                ->with('currentContract')
                ->get()
                ->keyBy('id');

            $created = 0;
            $updated = 0;
            $applied = 0;
            $skipped = [];
            $warnings = [];
            $changes = [];

            foreach ($aggregates as $employeeId => $aggregate) {
                $employee = $employees->get($employeeId);

                if ($employee === null || (int) $employee->company_id !== $companyId) {
                    abort(404);
                }

                $contract = $employee->currentContract;

                if (
                    $contract === null
                    || $contract->payroll_category !== PayrollCategory::Crew
                ) {
                    $skipped[] = [
                        'employee_id' => $employeeId,
                        'employee_number' => $employee->employee_no,
                        'employee_name' => $employee->name,
                        'reason' => 'No applicable daily crew contract.',
                    ];

                    continue;
                }

                if ($contract->resolvedSalaryStructure() === ContractSalaryStructure::Monthly) {
                    $skipped[] = [
                        'employee_id' => $employeeId,
                        'employee_number' => $employee->employee_no,
                        'employee_name' => $employee->name,
                        'reason' => 'Monthly crew contracts are not applied from Crew Operations timelines.',
                    ];

                    continue;
                }

                $payload = $this->buildOperationalPayload($aggregate, $preparation);
                $existing = $existingTimesheets->get($employeeId);

                if ($existing === null) {
                    $timesheet = CrewTimesheet::query()->create([
                        'company_id' => $companyId,
                        'employee_id' => $employeeId,
                        'period_id' => $period->id,
                        ...$payload,
                        'overtime_hours' => 0,
                        'overtime_amount' => 0,
                        'additional_amount' => 0,
                        'deduction_amount' => 0,
                        'remarks' => null,
                    ]);
                    $created++;
                    $changes[] = [
                        'employee_id' => $employeeId,
                        'action' => 'created',
                        'previous' => null,
                        'next' => $this->operationalSnapshot($timesheet),
                        'preserved_financial' => $this->financialSnapshot($timesheet),
                    ];
                } else {
                    $previousOperational = $this->operationalSnapshot($existing);
                    $preservedFinancial = $this->financialSnapshot($existing);

                    $existing->fill($payload);
                    $existing->save();

                    $updated++;
                    $changes[] = [
                        'employee_id' => $employeeId,
                        'action' => 'updated',
                        'previous' => $previousOperational,
                        'next' => $this->operationalSnapshot($existing->fresh() ?? $existing),
                        'preserved_financial' => $preservedFinancial,
                    ];
                }

                $applied++;
            }

            $previousStatus = $preparation->status->value;

            $preparation->fill([
                'status' => CrewTimesheetPreparationStatus::Applied,
                'applied_by' => $actor->id,
                'applied_at' => now(),
            ]);
            $preparation->save();

            activity()
                ->performedOn($preparation)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_timeline_applied',
                    'company_id' => $companyId,
                    'payroll_period_id' => $period->id,
                    'preparation_id' => $preparation->id,
                    'version' => $preparation->version,
                    'source_hash' => $preparation->source_hash,
                    'previous_status' => $previousStatus,
                    'new_status' => CrewTimesheetPreparationStatus::Applied->value,
                    'applied_employee_count' => $applied,
                    'created_timesheet_count' => $created,
                    'updated_timesheet_count' => $updated,
                    'skipped_employee_count' => count($skipped),
                    'changes' => $changes,
                ])
                ->log('Crew timesheet preparation applied to timesheets');

            return new ApplyCrewTimesheetPreparationResult(
                appliedEmployeeCount: $applied,
                createdTimesheetCount: $created,
                updatedTimesheetCount: $updated,
                skippedEmployeeCount: count($skipped),
                skippedEmployees: $skipped,
                warnings: $warnings,
            );
        });
    }

    private function idempotentResult(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
        int $companyId,
    ): ApplyCrewTimesheetPreparationResult {
        $timesheets = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('source', CrewTimesheetSource::CrewOperations)
            ->where('movement_source_hash', $preparation->source_hash)
            ->get();

        if ($timesheets->isEmpty()) {
            throw ValidationException::withMessages([
                'preparation' => 'This preparation is marked applied but linked timesheets were not found. Contact support before retrying.',
            ]);
        }

        return new ApplyCrewTimesheetPreparationResult(
            appliedEmployeeCount: $timesheets->count(),
            createdTimesheetCount: 0,
            updatedTimesheetCount: 0,
            skippedEmployeeCount: 0,
            skippedEmployees: [],
            warnings: ['Preparation was already applied. No duplicate timesheets were created.'],
            idempotent: true,
        );
    }

    /**
     * @param  Collection<int, CrewTimesheetPreparationLine>  $lines
     * @return array<int, array{
     *     sign_on_standby_from: string|null,
     *     sign_on_standby_to: string|null,
     *     sign_on_standby_days: float,
     *     onsite_from: string|null,
     *     onsite_to: string|null,
     *     onsite_days: float,
     *     sign_off_standby_from: string|null,
     *     sign_off_standby_to: string|null,
     *     sign_off_standby_days: float
     * }>
     */
    private function aggregatePayableLines(Collection $lines): array
    {
        /** @var array<int, array<string, mixed>> $aggregates */
        $aggregates = [];

        foreach ($lines as $line) {
            $days = (float) $line->days;

            if ($days <= 0) {
                continue;
            }

            if ($line->pay_category === null || $line->pay_category === CrewTimesheetPayCategory::Excluded) {
                continue;
            }

            $employeeId = (int) $line->employee_id;

            if (! isset($aggregates[$employeeId])) {
                $aggregates[$employeeId] = [
                    'sign_on_standby_from' => null,
                    'sign_on_standby_to' => null,
                    'sign_on_standby_days' => 0.0,
                    'onsite_from' => null,
                    'onsite_to' => null,
                    'onsite_days' => 0.0,
                    'sign_off_standby_from' => null,
                    'sign_off_standby_to' => null,
                    'sign_off_standby_days' => 0.0,
                ];
            }

            $from = $line->from_date?->toDateString();
            $to = $line->to_date?->toDateString();

            match ($line->pay_category) {
                CrewTimesheetPayCategory::SignOnStandby => $this->accumulate(
                    $aggregates[$employeeId],
                    'sign_on_standby',
                    $from,
                    $to,
                    $days,
                ),
                CrewTimesheetPayCategory::Onsite => $this->accumulate(
                    $aggregates[$employeeId],
                    'onsite',
                    $from,
                    $to,
                    $days,
                ),
                CrewTimesheetPayCategory::SignOffStandby => $this->accumulate(
                    $aggregates[$employeeId],
                    'sign_off_standby',
                    $from,
                    $to,
                    $days,
                ),
                default => null,
            };
        }

        return array_filter(
            $aggregates,
            fn (array $aggregate): bool => $aggregate['sign_on_standby_days'] > 0
                || $aggregate['onsite_days'] > 0
                || $aggregate['sign_off_standby_days'] > 0,
        );
    }

    /**
     * @param  array<string, mixed>  $aggregate
     */
    private function accumulate(
        array &$aggregate,
        string $prefix,
        ?string $from,
        ?string $to,
        float $days,
    ): void {
        $fromKey = "{$prefix}_from";
        $toKey = "{$prefix}_to";
        $daysKey = "{$prefix}_days";

        if ($from !== null && ($aggregate[$fromKey] === null || $from < $aggregate[$fromKey])) {
            $aggregate[$fromKey] = $from;
        }

        if ($to !== null && ($aggregate[$toKey] === null || $to > $aggregate[$toKey])) {
            $aggregate[$toKey] = $to;
        }

        $aggregate[$daysKey] = round((float) $aggregate[$daysKey] + $days, 2);
    }

    /**
     * @param  array{
     *     sign_on_standby_from: string|null,
     *     sign_on_standby_to: string|null,
     *     sign_on_standby_days: float,
     *     onsite_from: string|null,
     *     onsite_to: string|null,
     *     onsite_days: float,
     *     sign_off_standby_from: string|null,
     *     sign_off_standby_to: string|null,
     *     sign_off_standby_days: float
     * }  $aggregate
     * @return array<string, mixed>
     */
    private function buildOperationalPayload(
        array $aggregate,
        CrewTimesheetPreparation $preparation,
    ): array {
        $signOnDays = round((float) $aggregate['sign_on_standby_days'], 2);
        $signOffDays = round((float) $aggregate['sign_off_standby_days'], 2);
        $onsiteDays = round((float) $aggregate['onsite_days'], 2);
        $hasSignOn = $signOnDays > 0;
        $hasSignOff = $signOffDays > 0;

        $legacyStandbyFrom = null;
        $legacyStandbyTo = null;

        if ($hasSignOn && ! $hasSignOff) {
            $legacyStandbyFrom = $aggregate['sign_on_standby_from'];
            $legacyStandbyTo = $aggregate['sign_on_standby_to'];
        } elseif ($hasSignOff && ! $hasSignOn) {
            $legacyStandbyFrom = $aggregate['sign_off_standby_from'];
            $legacyStandbyTo = $aggregate['sign_off_standby_to'];
        }

        return [
            'sign_on_standby_from' => $hasSignOn ? $aggregate['sign_on_standby_from'] : null,
            'sign_on_standby_to' => $hasSignOn ? $aggregate['sign_on_standby_to'] : null,
            'sign_on_standby_days' => $signOnDays,
            'onsite_from' => $onsiteDays > 0 ? $aggregate['onsite_from'] : null,
            'onsite_to' => $onsiteDays > 0 ? $aggregate['onsite_to'] : null,
            'onsite_days' => $onsiteDays,
            'sign_off_standby_from' => $hasSignOff ? $aggregate['sign_off_standby_from'] : null,
            'sign_off_standby_to' => $hasSignOff ? $aggregate['sign_off_standby_to'] : null,
            'sign_off_standby_days' => $signOffDays,
            'standby_from' => $legacyStandbyFrom,
            'standby_to' => $legacyStandbyTo,
            'standby_days' => round($signOnDays + $signOffDays, 2),
            'source' => CrewTimesheetSource::CrewOperations,
            'crew_timesheet_preparation_id' => $preparation->id,
            'operational_approved_by' => $preparation->approved_by,
            'operational_approved_at' => $preparation->approved_at,
            'movement_source_hash' => $preparation->source_hash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalSnapshot(CrewTimesheet $timesheet): array
    {
        return [
            'standby_from' => $timesheet->standby_from?->toDateString(),
            'standby_to' => $timesheet->standby_to?->toDateString(),
            'standby_days' => $timesheet->standby_days,
            'sign_on_standby_from' => $timesheet->sign_on_standby_from?->toDateString(),
            'sign_on_standby_to' => $timesheet->sign_on_standby_to?->toDateString(),
            'sign_on_standby_days' => $timesheet->sign_on_standby_days,
            'onsite_from' => $timesheet->onsite_from?->toDateString(),
            'onsite_to' => $timesheet->onsite_to?->toDateString(),
            'onsite_days' => $timesheet->onsite_days,
            'sign_off_standby_from' => $timesheet->sign_off_standby_from?->toDateString(),
            'sign_off_standby_to' => $timesheet->sign_off_standby_to?->toDateString(),
            'sign_off_standby_days' => $timesheet->sign_off_standby_days,
            'source' => $timesheet->source?->value,
            'crew_timesheet_preparation_id' => $timesheet->crew_timesheet_preparation_id,
            'movement_source_hash' => $timesheet->movement_source_hash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSnapshot(CrewTimesheet $timesheet): array
    {
        return [
            'overtime_hours' => $timesheet->overtime_hours,
            'overtime_amount' => $timesheet->overtime_amount,
            'additional_amount' => $timesheet->additional_amount,
            'deduction_amount' => $timesheet->deduction_amount,
            'remarks' => $timesheet->remarks,
        ];
    }
}
