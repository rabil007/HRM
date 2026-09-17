<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewTimelineSourceHasher
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    /**
     * Builds a deterministic hash over every input that can change payroll
     * eligibility: the period boundaries, cutoff, the movement phases, the
     * period-applicable contract for each employee, and pending movement
     * correction state. Any change to these makes an existing preparation stale.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     */
    public function hash(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
        Collection $phases,
        ?CarbonInterface $effectiveCutoffDate = null,
    ): string {
        $employeeIds = $this->employeeIdsFromPhases($phases);
        $phaseIds = $this->phaseIdsFromPhases($phases);

        return $this->buildHash(
            $period,
            $cutoffDate,
            $phases,
            $effectiveCutoffDate,
            $this->contractFingerprints($period, $employeeIds),
            $this->pendingCorrectionFingerprints((int) $period->company_id, $phaseIds),
        );
    }

    /**
     * Builds the authoritative Apply hash from locked source rows. Performs no
     * additional ordinary source queries for phases, contracts, or corrections.
     */
    public function hashLockedSource(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
        LockedCrewTimelineSource $source,
        ?CarbonInterface $effectiveCutoffDate = null,
    ): string {
        $employeeIds = $this->employeeIdsFromPhases($source->phases);

        return $this->buildHash(
            $period,
            $cutoffDate,
            $source->phases,
            $effectiveCutoffDate,
            $this->contractFingerprintsFromResolved($employeeIds, $source->contractsByEmployeeId),
            $this->pendingCorrectionFingerprintsFromCollection($source->pendingCorrections),
        );
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @param  list<array<string, mixed>>  $contractFingerprints
     * @param  list<array<string, mixed>>  $pendingCorrectionFingerprints
     */
    private function buildHash(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
        Collection $phases,
        ?CarbonInterface $effectiveCutoffDate,
        array $contractFingerprints,
        array $pendingCorrectionFingerprints,
    ): string {
        $payload = [
            'period_id' => (int) $period->id,
            'period_start' => $period->start_date?->toDateString(),
            'period_end' => $period->end_date?->toDateString(),
            'cutoff_date' => $cutoffDate?->toDateString(),
            'effective_cutoff_date' => $effectiveCutoffDate?->toDateString(),
            'phases' => $phases
                ->map(fn (CrewAssignmentPhase $phase): array => [
                    'employee_id' => (int) $phase->assignment?->employee_id,
                    'assignment_id' => (int) $phase->crew_assignment_id,
                    'phase_id' => (int) $phase->id,
                    'phase_code' => $phase->phase_code?->value,
                    'phase_status' => $phase->status?->value,
                    'actual_start' => $phase->actual_start_at?->toIso8601String(),
                    'actual_end' => $phase->actual_end_at?->toIso8601String(),
                ])
                ->sortBy([
                    ['employee_id', 'asc'],
                    ['assignment_id', 'asc'],
                    ['phase_id', 'asc'],
                ])
                ->values()
                ->all(),
            'contracts' => $contractFingerprints,
            'pending_corrections' => $pendingCorrectionFingerprints,
        ];

        return hash('sha256', (string) json_encode($payload));
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<int>
     */
    private function employeeIdsFromPhases(Collection $phases): array
    {
        return $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->assignment?->employee_id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<int>
     */
    private function phaseIdsFromPhases(Collection $phases): array
    {
        return $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->id)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<array<string, mixed>>
     */
    private function contractFingerprints(PayrollPeriod $period, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $contracts = $this->resolveContract->resolveMany($period, $employeeIds);

        return $this->contractFingerprintsFromResolved($employeeIds, $contracts);
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  Collection<int, EmployeeContract|null>  $contracts
     * @return list<array<string, mixed>>
     */
    private function contractFingerprintsFromResolved(array $employeeIds, Collection $contracts): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return collect($employeeIds)
            ->sort()
            ->values()
            ->map(function (int $employeeId) use ($contracts): array {
                /** @var EmployeeContract|null $contract */
                $contract = $contracts->get($employeeId);

                return [
                    'employee_id' => $employeeId,
                    'contract_id' => $contract !== null ? (int) $contract->id : null,
                    'payroll_category' => $contract?->payroll_category?->value,
                    'salary_structure' => $contract?->resolvedSalaryStructure()->value,
                    'start_date' => $contract?->start_date?->toDateString(),
                    'end_date' => $contract?->end_date?->toDateString(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<int>  $phaseIds
     * @return list<array<string, mixed>>
     */
    private function pendingCorrectionFingerprints(int $companyId, array $phaseIds): array
    {
        if ($phaseIds === []) {
            return [];
        }

        $corrections = CrewMovementCorrection::query()
            ->where('company_id', $companyId)
            ->pending()
            ->whereIn('crew_assignment_phase_id', $phaseIds)
            ->orderBy('id')
            ->get(['id', 'crew_assignment_phase_id', 'status', 'updated_at']);

        return $this->pendingCorrectionFingerprintsFromCollection($corrections);
    }

    /**
     * @param  Collection<int, CrewMovementCorrection>  $corrections
     * @return list<array<string, mixed>>
     */
    private function pendingCorrectionFingerprintsFromCollection(Collection $corrections): array
    {
        return $corrections
            ->sortBy('id')
            ->values()
            ->map(fn (CrewMovementCorrection $correction): array => [
                'id' => (int) $correction->id,
                'phase_id' => (int) $correction->crew_assignment_phase_id,
                'status' => $correction->status?->value,
                'updated_at' => $correction->updated_at?->toIso8601String(),
            ])
            ->all();
    }
}
