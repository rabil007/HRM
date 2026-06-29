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
    /**
     * @param  array<int, UploadedFile>|UploadedFile|null  $proofFiles
     */
    public function handle(PayrollPeriod $period, array|UploadedFile|null $proofFiles = null): PayrollPeriod
    {
        if (! $period->canMarkPaid()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only approved pay periods with payroll records can be marked as paid.',
            ]);
        }

        return DB::transaction(function () use ($period, $proofFiles): PayrollPeriod {
            $paidAt = now();
            $files = is_array($proofFiles)
                ? $proofFiles
                : ($proofFiles instanceof UploadedFile ? [$proofFiles] : []);

            $paths = $period->payment_proof_paths ?? [];
            if ($period->payment_proof_path !== null && ! in_array($period->payment_proof_path, $paths, true)) {
                array_unshift($paths, $period->payment_proof_path);
            }

            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $paths[] = UploadedFileStorage::store($file, 'payroll-periods/payment-proofs');
                }
            }

            $paths = array_values(array_unique($paths));
            $primaryPath = $paths[0] ?? null;

            $period->payrollRecords()->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $period->update([
                'status' => PayrollPeriodStatus::Paid,
                'payment_proof_path' => $primaryPath,
                'payment_proof_paths' => $paths,
            ]);

            return $period->refresh();
        });
    }
}
