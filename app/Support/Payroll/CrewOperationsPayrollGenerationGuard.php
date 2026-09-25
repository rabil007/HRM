<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewOperationsPayrollGenerationGuard
{
    public const MISSING_APPLIED_MESSAGE = 'Populate Crew Timesheets from Crew Assignments, or enter Manual / Excel timesheet data, before generating payroll.';

    public const MULTIPLE_APPLIED_MESSAGE = 'Multiple Applied Crew Timesheets were found for this pay period.';

    public const BLOCKING_WARNINGS_MESSAGE = 'Crew Timesheet data still has blocking warnings and cannot be used for payroll generation.';

    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly CrewTimeline\CrewTimesheetPreparationSkipResolver $skipResolver,
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
        // Exclusive mode no longer depends on the retired apply/approval workflow.
        // Ready daily crew simply need a usable timesheet on the draft period.
        return $this->validateHybridReadiness($period, $employees, $companyId);
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

        if ($preparation !== null && $this->preparationHasNonBypassableIntegrityProblems($preparation)) {
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
                    "Daily crew employee {$employee->name} is missing a timesheet. Enter Manual or Excel data, or populate from Crew Assignments.",
                    $preparation,
                    $employeeId,
                );
            }

            $source = $timesheet->resolvedSource();

            if (! in_array($source, [
                CrewTimesheetSource::Manual,
                CrewTimesheetSource::Import,
                CrewTimesheetSource::CrewOperations,
            ], true)) {
                return $this->result(
                    false,
                    "Daily crew employee {$employee->name} timesheet source must be Manual, Import, or Crew Assignments.",
                    $preparation,
                    $employeeId,
                );
            }
        }

        return $this->result(true, null, $preparation);
    }

    public function preparationHasBlockingWarnings(CrewTimesheetPreparation $preparation): bool
    {
        return $this->skipResolver->hasUnresolvedBlockingWarnings($preparation);
    }

    /**
     * @param  list<int>  $includedEmployeeIds
     */
    public function preparationHasBlockingWarningsForIncludedEmployees(
        CrewTimesheetPreparation $preparation,
        array $includedEmployeeIds,
    ): bool {
        return $this->skipResolver->hasBlockingWarningsAffectingIncludedEmployees(
            $preparation,
            $includedEmployeeIds,
        );
    }

    /**
     * Non-bypassable integrity problems (e.g. cross-company reference) still block
     * generation. Correctable operational prep warnings do not — current timesheet
     * data is the generation authority after Populate.
     */
    public function preparationHasNonBypassableIntegrityProblems(
        CrewTimesheetPreparation $preparation,
    ): bool {
        return $this->skipResolver->hasNonBypassableIntegrityProblems($preparation);
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
            return "Daily crew employee {$employee->name} is missing a Crew Assignment timesheet linked to the Applied timesheet.";
        }

        if ($timesheet->resolvedSource() !== CrewTimesheetSource::CrewOperations) {
            return "Daily crew employee {$employee->name} timesheet source must be Crew Assignments.";
        }

        if ($preparation === null) {
            return "Daily crew employee {$employee->name} has Crew Assignment timesheet data but no Applied timesheet was found.";
        }

        if ((int) $timesheet->crew_timesheet_preparation_id !== (int) $preparation->id) {
            return "Daily crew employee {$employee->name} timesheet is not linked to the Applied timesheet.";
        }

        if ($timesheet->movement_source_hash !== $preparation->source_hash) {
            return "Daily crew employee {$employee->name} timesheet movement source hash does not match the Applied timesheet.";
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
