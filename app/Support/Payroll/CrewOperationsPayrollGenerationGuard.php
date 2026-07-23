<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\PayableCrewPreparationLines;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewOperationsPayrollGenerationGuard
{
    public const MISSING_APPLIED_MESSAGE = 'Apply the approved Crew Operations timeline before generating payroll.';

    public const MULTIPLE_APPLIED_MESSAGE = 'Multiple Applied Crew Operations timelines were found for this pay period.';

    public const BLOCKING_WARNINGS_MESSAGE = 'The Applied Crew Operations timeline still has blocking warnings and cannot be used for payroll generation.';

    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    /**
     * Non-mutating readiness validation shared by the UI badge and backend
     * generation so both always agree on the same blocking reason.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *     ready: bool,
     *     blocking_reason: string|null,
     *     applied_preparation_id: int|null,
     *     applied_preparation_version: int|null,
     *     affected_employee_id: int|null,
     *     preparation: CrewTimesheetPreparation|null
     * }
     */
    public function validateReadiness(
        PayrollPeriod $period,
        Collection $employees,
        int $companyId,
    ): array {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        if (! $period->isCrew()) {
            return $this->result(true);
        }

        if ($period->requiresExclusiveCrewOperationsTimesheets()) {
            return $this->validateExclusiveCrewOperationsReadiness($period, $employees, $companyId);
        }

        if ($period->usesMixedTimesheetSources()) {
            return $this->validateHybridReadiness($period, $employees, $companyId);
        }

        return $this->result(true);
    }

    /**
     * UI-facing readiness. Prefer the structured generation preview.
     *
     * @return array<string, mixed>
     */
    public function readiness(PayrollPeriod $period, int $companyId): array
    {
        return app(BuildCrewPayrollGenerationPreview::class)
            ->handle($period, $companyId)
            ->toArray();
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    public function assertReadyForGeneration(
        PayrollPeriod $period,
        Collection $employees,
        int $companyId,
    ): ?CrewTimesheetPreparation {
        CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->lockForUpdate()
            ->get();

        if ($period->requiresExclusiveCrewOperationsTimesheets()) {
            $readiness = $this->validateReadiness($period, $employees, $companyId);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'period_id' => $readiness['blocking_reason'] ?? self::MISSING_APPLIED_MESSAGE,
                ]);
            }

            return $readiness['preparation'];
        }

        $excluded = array_values(array_unique(array_map(
            intval(...),
            $period->excluded_employee_ids ?? [],
        )));

        $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
            $period,
            $companyId,
            $excluded,
        );

        if ($preview->blockingCount > 0) {
            throw ValidationException::withMessages([
                'period_id' => $preview->blockingIssues[0]['message']
                    ?? 'Payroll generation is blocked by invalid approved timesheet data.',
            ]);
        }

        if ($preview->readyCount === 0) {
            throw ValidationException::withMessages([
                'period_id' => 'No employees are ready for payroll.',
            ]);
        }

        return $preview->appliedPreparationId !== null
            ? CrewTimesheetPreparation::query()
                ->where('company_id', $companyId)
                ->whereKey($preview->appliedPreparationId)
                ->first()
            : null;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *     ready: bool,
     *     blocking_reason: string|null,
     *     applied_preparation_id: int|null,
     *     applied_preparation_version: int|null,
     *     affected_employee_id: int|null,
     *     preparation: CrewTimesheetPreparation|null
     * }
     */
    private function validateExclusiveCrewOperationsReadiness(
        PayrollPeriod $period,
        Collection $employees,
        int $companyId,
    ): array {
        $applied = $this->appliedPreparations($period, $companyId);

        if ($applied->count() === 0) {
            return $this->result(false, self::MISSING_APPLIED_MESSAGE);
        }

        if ($applied->count() > 1) {
            return $this->result(false, self::MULTIPLE_APPLIED_MESSAGE);
        }

        /** @var CrewTimesheetPreparation $preparation */
        $preparation = $applied->first();

        if ($this->preparationHasBlockingWarnings($preparation)) {
            return $this->result(false, self::BLOCKING_WARNINGS_MESSAGE, $preparation);
        }

        $payableEmployeeIds = PayableCrewPreparationLines::payableEmployeeIds($companyId, (int) $preparation->id);
        $contracts = $this->resolveContract->resolveMany(
            $period,
            $employees->pluck('id')->map(intval(...))->all(),
        );

        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $contract = $contracts->get((int) $employee->id);
            $structure = $contract?->resolvedSalaryStructure() ?? ContractSalaryStructure::Daily;

            if ($structure === ContractSalaryStructure::Monthly) {
                continue;
            }

            if ($contract !== null && $contract->payroll_category !== PayrollCategory::Crew) {
                continue;
            }

            if (! in_array((int) $employee->id, $payableEmployeeIds, true)) {
                continue;
            }

            $blockingReason = $this->dailyTimesheetLinkReason($employee, $period, $preparation, $companyId);

            if ($blockingReason !== null) {
                return $this->result(false, $blockingReason, $preparation, (int) $employee->id);
            }
        }

        return $this->result(true, null, $preparation);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array{
     *     ready: bool,
     *     blocking_reason: string|null,
     *     applied_preparation_id: int|null,
     *     applied_preparation_version: int|null,
     *     affected_employee_id: int|null,
     *     preparation: CrewTimesheetPreparation|null
     * }
     */
    private function validateHybridReadiness(
        PayrollPeriod $period,
        Collection $employees,
        int $companyId,
    ): array {
        $applied = $this->appliedPreparations($period, $companyId);

        if ($applied->count() > 1) {
            return $this->result(false, self::MULTIPLE_APPLIED_MESSAGE);
        }

        /** @var CrewTimesheetPreparation|null $preparation */
        $preparation = $applied->first();

        if ($preparation !== null && $this->preparationHasBlockingWarnings($preparation)) {
            return $this->result(false, self::BLOCKING_WARNINGS_MESSAGE, $preparation);
        }

        $contracts = $this->resolveContract->resolveMany(
            $period,
            $employees->pluck('id')->map(intval(...))->all(),
        );

        $timesheets = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employees->pluck('id')->map(intval(...))->all() ?: [0])
            ->with('preparation')
            ->get()
            ->keyBy(fn (CrewTimesheet $timesheet) => (int) $timesheet->employee_id);

        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $employeeId = (int) $employee->id;
            $contract = $contracts->get($employeeId);

            if ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                continue;
            }

            $structure = $contract->resolvedSalaryStructure();
            $timesheet = $timesheets->get($employeeId);

            if ($structure === ContractSalaryStructure::Monthly) {
                continue;
            }

            if ($timesheet === null) {
                return $this->result(
                    false,
                    "Daily crew employee {$employee->name} is missing a timesheet. Enter Manual or Excel data, or apply Crew Operations movement data.",
                    $preparation,
                    $employeeId,
                );
            }

            if ($timesheet->source === CrewTimesheetSource::CrewOperations) {
                if ($preparation === null) {
                    return $this->result(
                        false,
                        "Daily crew employee {$employee->name} has Crew Operations timesheet data but no Applied timeline was found.",
                        null,
                        $employeeId,
                    );
                }

                $blockingReason = $this->dailyTimesheetLinkReason($employee, $period, $preparation, $companyId);

                if ($blockingReason !== null) {
                    return $this->result(false, $blockingReason, $preparation, $employeeId);
                }

                continue;
            }

            if (! in_array($timesheet->source, [CrewTimesheetSource::Manual, CrewTimesheetSource::Import], true)) {
                return $this->result(
                    false,
                    "Daily crew employee {$employee->name} timesheet source must be Manual, Import, or Crew Operations.",
                    $preparation,
                    $employeeId,
                );
            }
        }

        return $this->result(true, null, $preparation);
    }

    public function preparationHasBlockingWarnings(CrewTimesheetPreparation $preparation): bool
    {
        return $preparation->lines()
            ->whereNotNull('warning_code')
            ->get(['warning_code'])
            ->contains(function ($line): bool {
                $code = CrewTimelineWarningCode::tryFrom((string) $line->warning_code);

                return $code !== null && $code->isBlocking();
            });
    }

    public function dailyTimesheetLinkReason(
        Employee $employee,
        PayrollPeriod $period,
        ?CrewTimesheetPreparation $preparation,
        int $companyId,
        ?CrewTimesheet $timesheet = null,
    ): ?string {
        $timesheet ??= CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->first();

        if ($timesheet === null) {
            return "Daily crew employee {$employee->name} is missing a Crew Operations timesheet linked to the Applied timeline.";
        }

        if ($timesheet->source !== CrewTimesheetSource::CrewOperations) {
            return "Daily crew employee {$employee->name} timesheet source must be Crew Operations.";
        }

        if ($preparation === null) {
            return "Daily crew employee {$employee->name} has Crew Operations timesheet data but no Applied timeline was found.";
        }

        if ((int) $timesheet->crew_timesheet_preparation_id !== (int) $preparation->id) {
            return "Daily crew employee {$employee->name} timesheet is not linked to the Applied timeline.";
        }

        if ($timesheet->movement_source_hash !== $preparation->source_hash) {
            return "Daily crew employee {$employee->name} timesheet movement source hash does not match the Applied timeline.";
        }

        if ($timesheet->operational_approved_by === null || $timesheet->operational_approved_at === null) {
            return "Daily crew employee {$employee->name} timesheet is missing operational approval metadata.";
        }

        return null;
    }

    /**
     * @return Collection<int, CrewTimesheetPreparation>
     */
    private function appliedPreparations(PayrollPeriod $period, int $companyId): Collection
    {
        return CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->get();
    }

    /**
     * @return array{
     *     ready: bool,
     *     blocking_reason: string|null,
     *     applied_preparation_id: int|null,
     *     applied_preparation_version: int|null,
     *     affected_employee_id: int|null,
     *     preparation: CrewTimesheetPreparation|null
     * }
     */
    private function result(
        bool $ready,
        ?string $blockingReason = null,
        ?CrewTimesheetPreparation $preparation = null,
        ?int $affectedEmployeeId = null,
    ): array {
        return [
            'ready' => $ready,
            'blocking_reason' => $blockingReason,
            'applied_preparation_id' => $preparation?->id,
            'applied_preparation_version' => $preparation?->version,
            'affected_employee_id' => $affectedEmployeeId,
            'preparation' => $preparation,
        ];
    }
}
