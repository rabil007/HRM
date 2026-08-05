<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\AssertPayrollAllocationLifecycle;
use App\Support\Payroll\PersistPayrollWorkAllocations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class RevertPayrollPeriodToProcessing
{
    public function __construct(
        private readonly PersistPayrollWorkAllocations $persistAllocations,
        private readonly AssertPayrollAllocationLifecycle $assertLifecycle,
    ) {}

    public function handle(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->canRevertToProcessing()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only approved pay periods can be reverted to processing.',
            ]);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $period->payrollRecords()
                ->get()
                ->each(function (PayrollRecord $record): void {
                    if (filled($record->payslip_path) && Storage::disk('local')->exists($record->payslip_path)) {
                        Storage::disk('local')->delete($record->payslip_path);
                    }
                });

            // Keep day locks; move approved allocations back to reserved so
            // draft records and reserved allocations stay aligned.
            $this->persistAllocations->transitionApprovedBackToReserved($period);

            $period->payrollRecords()->update([
                'status' => 'draft',
                'payslip_path' => null,
                'wps_reference' => null,
                'wps_agent_ref' => null,
                'wps_status' => null,
                'wps_submitted_at' => null,
            ]);

            $period->update([
                'status' => PayrollPeriodStatus::Processing,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $this->assertLifecycle->assertAfterRevertToProcessing($period->fresh() ?? $period);

            return $period->refresh();
        });
    }
}
