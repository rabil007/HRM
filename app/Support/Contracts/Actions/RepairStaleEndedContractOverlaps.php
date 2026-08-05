<?php

namespace App\Support\Contracts\Actions;

use App\Models\EmployeeContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Caps ended contracts whose end dates still overlap a later contract
 * for the same employee. This repairs legacy rows where status was set
 * to ended without trimming the end date when a successor opened.
 */
final class RepairStaleEndedContractOverlaps
{
    /**
     * @return list<array{contract_id: int, employee_id: int, previous_end_date: ?string, new_end_date: string}>
     */
    public function handle(?int $companyId = null, bool $dryRun = false): array
    {
        $repairs = [];

        $query = EmployeeContract::query()
            ->where('status', 'ended')
            ->whereNotNull('start_date')
            ->orderBy('employee_id')
            ->orderBy('start_date');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $query->chunkById(200, function (Collection $endedContracts) use (&$repairs, $dryRun): void {
            foreach ($endedContracts as $endedContract) {
                /** @var EmployeeContract $endedContract */
                $successorStart = EmployeeContract::query()
                    ->where('company_id', $endedContract->company_id)
                    ->where('employee_id', $endedContract->employee_id)
                    ->where('payroll_category', $endedContract->payroll_category)
                    ->where('start_date', '>', $endedContract->start_date)
                    ->orderBy('start_date')
                    ->value('start_date');

                if ($successorStart === null) {
                    continue;
                }

                $successorStartDate = Carbon::parse($successorStart)->startOfDay();
                $proposedEndDate = $successorStartDate->copy()->subDay();

                $currentEnd = $endedContract->end_date?->copy()->startOfDay();

                if ($currentEnd !== null && $currentEnd->lessThanOrEqualTo($proposedEndDate)) {
                    continue;
                }

                if ($proposedEndDate->lessThan($endedContract->start_date->copy()->startOfDay())) {
                    continue;
                }

                $repairs[] = [
                    'contract_id' => (int) $endedContract->id,
                    'employee_id' => (int) $endedContract->employee_id,
                    'previous_end_date' => $currentEnd?->toDateString(),
                    'new_end_date' => $proposedEndDate->toDateString(),
                ];

                if (! $dryRun) {
                    $endedContract->update([
                        'end_date' => $proposedEndDate->toDateString(),
                    ]);
                }
            }
        });

        return $repairs;
    }
}
