<?php

namespace App\Support\Payroll;

use App\Enums\PayrollWorkAllocationStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Validation\ValidationException;

/**
 * Post-transition invariants for payroll_work_allocations lifecycle.
 */
final class AssertPayrollAllocationLifecycle
{
    public function assertAfterApprove(PayrollPeriod $period): void
    {
        $companyId = (int) $period->company_id;
        $periodId = (int) $period->id;

        $activeCount = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Reserved->value,
                PayrollWorkAllocationStatus::Paid->value,
                PayrollWorkAllocationStatus::Reversed->value,
            ])
            ->count();

        if ($activeCount > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after approve: unexpected non-approved allocations remain.',
            ]);
        }

        $recordIds = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->pluck('id');

        if ($recordIds->isEmpty()) {
            return;
        }

        $notApproved = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)
            ->whereIn('payroll_record_id', $recordIds)
            ->where('status', '!=', PayrollWorkAllocationStatus::Approved->value)
            ->count();

        if ($notApproved > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after approve: all record allocations must be approved.',
            ]);
        }
    }

    public function assertAfterPaid(PayrollPeriod $period): void
    {
        $companyId = (int) $period->company_id;
        $periodId = (int) $period->id;

        $notPaid = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Reserved->value,
                PayrollWorkAllocationStatus::Approved->value,
            ])
            ->count();

        if ($notPaid > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after paid: all allocations must be paid.',
            ]);
        }
    }

    public function assertAfterDraftCancel(PayrollPeriod $period): void
    {
        $reserved = PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->where('status', PayrollWorkAllocationStatus::Reserved->value)
            ->count();

        if ($reserved > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after draft cancel: reserved allocations remain.',
            ]);
        }
    }

    public function assertAfterApprovedCancel(PayrollPeriod $period): void
    {
        $companyId = (int) $period->company_id;
        $periodId = (int) $period->id;

        $notReversed = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Reserved->value,
                PayrollWorkAllocationStatus::Approved->value,
                PayrollWorkAllocationStatus::Paid->value,
            ])
            ->count();

        if ($notReversed > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after approved cancel: all allocations must be reversed.',
            ]);
        }

        $nonCancelled = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->where('status', '!=', 'cancelled')
            ->count();

        if ($nonCancelled > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after approved cancel: payroll records must be cancelled.',
            ]);
        }
    }

    public function assertAfterRevertToProcessing(PayrollPeriod $period): void
    {
        $companyId = (int) $period->company_id;
        $periodId = (int) $period->id;

        $unexpected = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $periodId)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved->value,
                PayrollWorkAllocationStatus::Paid->value,
            ])
            ->count();

        if ($unexpected > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after revert to processing: approved/paid allocations remain.',
            ]);
        }

        $nonDraft = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->where('status', '!=', 'draft')
            ->count();

        if ($nonDraft > 0) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll allocation lifecycle invariant failed after revert to processing: payroll records must be draft.',
            ]);
        }
    }
}
