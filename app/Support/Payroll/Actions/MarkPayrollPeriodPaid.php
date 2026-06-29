<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarkPayrollPeriodPaid
{
    public function handle(PayrollPeriod $period, ?UploadedFile $proofFile = null): PayrollPeriod
    {
        if (! $period->canMarkPaid()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only approved pay periods with payroll records can be marked as paid.',
            ]);
        }

        return DB::transaction(function () use ($period, $proofFile): PayrollPeriod {
            $paidAt = now();
            $proofPath = $proofFile !== null
                ? UploadedFileStorage::store($proofFile, 'payroll-periods/payment-proofs')
                : $period->payment_proof_path;

            $period->payrollRecords()->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $period->update([
                'status' => PayrollPeriodStatus::Paid,
                'payment_proof_path' => $proofPath,
            ]);

            return $period->refresh();
        });
    }
}
