<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Support\Payroll\AssertPayrollAllocationLifecycle;
use App\Support\Payroll\PersistPayrollWorkAllocations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelPayrollPeriod
{
    public function __construct(
        private readonly PersistPayrollWorkAllocations $persistAllocations,
        private readonly AssertPayrollAllocationLifecycle $assertLifecycle,
    ) {}

    public function handle(PayrollPeriod $period, ?string $reason = null): PayrollPeriod
    {
        if (! $period->canCancel()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only draft, processing, or approved pay periods can be cancelled.',
            ]);
        }

        $wasApproved = $period->status === PayrollPeriodStatus::Approved;
        $cancelReason = filled($reason)
            ? (string) $reason
            : ($wasApproved
                ? 'Payroll period cancelled while approved.'
                : 'Payroll period cancelled.');

        return DB::transaction(function () use ($period, $wasApproved, $cancelReason): PayrollPeriod {
            if ($wasApproved) {
                // Preserve financial records: reverse allocations, keep payroll_record_id linked,
                // mark records cancelled. Do not forceDelete records, salary inputs, payslips, or WPS fields.
                $this->persistAllocations->reverseForPeriod($period, $cancelReason);

                $period->payrollRecords()->update(['status' => 'cancelled']);

                $period->update([
                    'status' => PayrollPeriodStatus::Cancelled,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);

                $this->assertLifecycle->assertAfterApprovedCancel($period->fresh() ?? $period);
            } else {
                $this->persistAllocations->releaseReservedForPeriod($period);

                $period->payrollRecords()->forceDelete();
                $period->salaryInputs()->forceDelete();

                $period->update([
                    'status' => PayrollPeriodStatus::Cancelled,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);

                $this->assertLifecycle->assertAfterDraftCancel($period->fresh() ?? $period);
            }

            activity()
                ->performedOn($period)
                ->withProperties([
                    'event' => 'payroll_period_cancelled',
                    'company_id' => (int) $period->company_id,
                    'payroll_period_id' => (int) $period->id,
                    'was_approved' => $wasApproved,
                    'reason' => $cancelReason,
                    'preserved_financial_records' => $wasApproved,
                ])
                ->log($wasApproved
                    ? 'Approved payroll period cancelled; financial records preserved'
                    : 'Payroll period cancelled');

            return $period->refresh();
        });
    }
}
