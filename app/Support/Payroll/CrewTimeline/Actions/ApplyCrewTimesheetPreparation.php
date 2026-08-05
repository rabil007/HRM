<?php

namespace App\Support\Payroll\CrewTimeline\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\ApplyCrewTimesheetPreparationResult;
use App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationWorkflowGuard;
use App\Support\Payroll\CrewTimeline\PayableCrewPreparationLines;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Payroll\SyncCrewTimesheetParentFromSegments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyCrewTimesheetPreparation
{
    public function __construct(
        private readonly CrewTimesheetPreparationWorkflowGuard $guard,
        private readonly CrewTimelineFreshnessChecker $freshnessChecker,
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly SyncCrewTimesheetParentFromSegments $syncParentFromSegments,
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
                ->with([
                    'employee:id,employee_no,name,company_id',
                    'assignment:id,company_id,vessel_id,client_id,rank_id',
                ])
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

            $linesByEmployee = $this->groupPayableLinesByEmployee($lines);
            $employeeIds = array_keys($linesByEmployee);

            $existingTimesheets = CrewTimesheet::withTrashed()
                ->where('company_id', $companyId)
                ->where('period_id', $period->id)
                ->whereIn('employee_id', $employeeIds !== [] ? $employeeIds : [0])
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');

            $employees = Employee::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $employeeIds !== [] ? $employeeIds : [0])
                ->get()
                ->keyBy('id');

            $contracts = $this->resolveContract->resolveMany($period, $employeeIds);

            $created = 0;
            $updated = 0;
            $applied = 0;
            $skipped = [];
            $warnings = [];
            $changes = [];

            foreach ($linesByEmployee as $employeeId => $employeeLines) {
                $employee = $employees->get($employeeId);

                if ($employee === null || (int) $employee->company_id !== $companyId) {
                    abort(404);
                }

                $contract = $contracts->get($employeeId);

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

                $metadataPayload = $this->buildOperationalMetadataPayload($preparation);
                $existing = $existingTimesheets->get($employeeId);

                if ($existing === null) {
                    $timesheet = CrewTimesheet::query()->create([
                        'company_id' => $companyId,
                        'employee_id' => $employeeId,
                        'period_id' => $period->id,
                        ...$metadataPayload,
                        'sign_on_standby_from' => null,
                        'sign_on_standby_to' => null,
                        'sign_on_standby_days' => 0,
                        'onsite_from' => null,
                        'onsite_to' => null,
                        'onsite_days' => 0,
                        'sign_off_standby_from' => null,
                        'sign_off_standby_to' => null,
                        'sign_off_standby_days' => 0,
                        'overtime_hours' => 0,
                        'overtime_amount' => 0,
                        'additional_amount' => 0,
                        'deduction_amount' => 0,
                        'remarks' => null,
                    ]);
                    $created++;
                    $action = 'created';
                    $previousOperational = null;
                    $preservedFinancial = $this->financialSnapshot($timesheet);
                } else {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $previousOperational = $this->operationalSnapshot($existing);
                    $preservedFinancial = $this->financialSnapshot($existing);

                    $existing->fill($metadataPayload);
                    $existing->save();
                    $timesheet = $existing;
                    $updated++;
                    $action = 'updated';
                }

                $this->replaceOperationalSegments($timesheet, $employeeLines, $companyId, $period);
                $timesheet = $this->syncParentFromSegments->handle(
                    $timesheet->fresh(['segments', 'period']) ?? $timesheet,
                    $period,
                );

                $changes[] = [
                    'employee_id' => $employeeId,
                    'action' => $action,
                    'previous' => $previousOperational,
                    'next' => $this->operationalSnapshot($timesheet),
                    'preserved_financial' => $preservedFinancial,
                    'segment_count' => $timesheet->segments->count(),
                ];

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

        if ($timesheets->isEmpty() && $this->expectsPayableDailyTimesheets($preparation, $period, $companyId)) {
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
     * An empty Applied preparation with zero payable Daily employees is valid and
     * must remain idempotent; only expect linked timesheets when payable Daily
     * employees actually exist in the applied snapshot.
     */
    private function expectsPayableDailyTimesheets(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
        int $companyId,
    ): bool {
        $payableEmployeeIds = PayableCrewPreparationLines::payableEmployeeIds($companyId, (int) $preparation->id);

        if ($payableEmployeeIds === []) {
            return false;
        }

        $contracts = $this->resolveContract->resolveMany($period, $payableEmployeeIds);

        foreach ($payableEmployeeIds as $employeeId) {
            $contract = $contracts->get($employeeId);

            if (
                $contract !== null
                && $contract->payroll_category === PayrollCategory::Crew
                && $contract->resolvedSalaryStructure() !== ContractSalaryStructure::Monthly
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, CrewTimesheetPreparationLine>  $lines
     * @return array<int, list<CrewTimesheetPreparationLine>>
     */
    private function groupPayableLinesByEmployee(Collection $lines): array
    {
        /** @var array<int, list<CrewTimesheetPreparationLine>> $grouped */
        $grouped = [];

        foreach ($lines as $line) {
            if (! PayableCrewPreparationLines::isPayable($line)) {
                continue;
            }

            $grouped[(int) $line->employee_id][] = $line;
        }

        return $grouped;
    }

    /**
     * Replace current-period operational segments while preserving prior-period Manual/Import source.
     *
     * - Soft-delete CrewOperations segments always.
     * - Soft-delete Manual/Import segments that overlap the current payroll period.
     * - When a Manual/Import segment crosses the period boundary, recreate a prior-only
     *   clipped segment (from_date → period.start-1) so June arrears source survives.
     * - Segments entirely before period.start are preserved unchanged.
     *
     * @param  list<CrewTimesheetPreparationLine>  $lines
     */
    private function replaceOperationalSegments(
        CrewTimesheet $timesheet,
        array $lines,
        int $companyId,
        PayrollPeriod $period,
    ): void {
        $periodStart = CarbonImmutable::parse($period->start_date)->startOfDay();
        $periodEnd = CarbonImmutable::parse($period->end_date)->startOfDay();
        $priorCutoff = $periodStart->subDay();

        $existing = CrewTimesheetSegment::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_id', $timesheet->id)
            ->whereIn('source', [
                CrewTimesheetSource::Manual->value,
                CrewTimesheetSource::Import->value,
                CrewTimesheetSource::CrewOperations->value,
            ])
            ->lockForUpdate()
            ->get();

        /** @var list<array<string, mixed>> $priorClips */
        $priorClips = [];

        foreach ($existing as $segment) {
            $source = $segment->source instanceof CrewTimesheetSource
                ? $segment->source
                : CrewTimesheetSource::tryFrom((string) $segment->source);

            if ($source === CrewTimesheetSource::CrewOperations) {
                $segment->delete();

                continue;
            }

            if (! in_array($source, [CrewTimesheetSource::Manual, CrewTimesheetSource::Import], true)) {
                continue;
            }

            if ($segment->from_date === null || $segment->to_date === null) {
                $segment->delete();

                continue;
            }

            $from = CarbonImmutable::parse($segment->from_date)->startOfDay();
            $to = CarbonImmutable::parse($segment->to_date)->startOfDay();

            // Entirely before the payroll period — keep as prior-period arrears source.
            if ($to->lessThan($periodStart)) {
                continue;
            }

            $overlapsCurrent = $from->lessThanOrEqualTo($periodEnd) && $to->greaterThanOrEqualTo($periodStart);

            if (! $overlapsCurrent) {
                continue;
            }

            // Crossing or fully inside current period: soft-delete and clip prior portion if any.
            if ($from->lessThan($periodStart)) {
                $priorClips[] = [
                    'pay_category' => $segment->pay_category,
                    'from_date' => $from->toDateString(),
                    'to_date' => $priorCutoff->toDateString(),
                    'days' => (float) ($from->diffInDays($priorCutoff) + 1),
                    'source' => $source,
                    'crew_assignment_id' => $segment->crew_assignment_id,
                    'crew_assignment_phase_id' => $segment->crew_assignment_phase_id,
                    'crew_timesheet_preparation_line_id' => null,
                    'vessel_id' => $segment->vessel_id,
                    'client_id' => $segment->client_id,
                    'rank_id' => $segment->rank_id,
                    'remarks' => $segment->remarks,
                ];
            }

            $segment->delete();
        }

        $sequence = 1;

        foreach ($priorClips as $clip) {
            CrewTimesheetSegment::query()->create([
                'company_id' => $companyId,
                'crew_timesheet_id' => $timesheet->id,
                'sequence' => $sequence++,
                ...$clip,
            ]);
        }

        foreach ($lines as $line) {
            $assignment = $line->assignment;

            CrewTimesheetSegment::query()->create([
                'company_id' => $companyId,
                'crew_timesheet_id' => $timesheet->id,
                'sequence' => $sequence++,
                'pay_category' => $line->pay_category,
                'from_date' => $line->from_date,
                'to_date' => $line->to_date,
                'days' => $line->days,
                'source' => CrewTimesheetSource::CrewOperations,
                'crew_assignment_id' => $line->crew_assignment_id,
                'crew_assignment_phase_id' => $line->crew_assignment_phase_id,
                'crew_timesheet_preparation_line_id' => $line->id,
                'vessel_id' => $assignment?->vessel_id,
                'client_id' => $assignment?->client_id,
                'rank_id' => $assignment?->rank_id,
                'remarks' => $line->remarks,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOperationalMetadataPayload(CrewTimesheetPreparation $preparation): array
    {
        return [
            'source' => CrewTimesheetSource::CrewOperations,
            'approval_status' => CrewTimesheetApprovalStatus::Approved,
            'crew_timesheet_preparation_id' => $preparation->id,
            'operational_approved_by' => $preparation->approved_by,
            'operational_approved_at' => $preparation->approved_at,
            'approved_by' => $preparation->approved_by,
            'approved_at' => $preparation->approved_at,
            'submitted_by' => null,
            'submitted_at' => null,
            'returned_by' => null,
            'returned_at' => null,
            'return_reason' => null,
            'movement_source_hash' => $preparation->source_hash,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalSnapshot(CrewTimesheet $timesheet): array
    {
        return [
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
