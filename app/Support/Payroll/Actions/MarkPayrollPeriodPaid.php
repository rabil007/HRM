<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Support\Settings\CompanyTimezone;
use App\Support\Uploads\UploadedFileStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarkPayrollPeriodPaid
{
    /**
     * @param  array<int, UploadedFile>|UploadedFile|null  $proofFiles
     */
    public function handle(
        PayrollPeriod $period,
        array|UploadedFile|null $proofFiles = null,
        ?string $paymentDate = null,
    ): PayrollPeriod {
        if (! $period->canMarkPaid()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only approved pay periods with payroll records can be marked as paid.',
            ]);
        }

        return DB::transaction(function () use ($period, $proofFiles, $paymentDate): PayrollPeriod {
            $locked = PayrollPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->canMarkPaid()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Only approved pay periods with payroll records can be marked as paid.',
                ]);
            }

            $timezone = CompanyTimezone::forCompany((int) $locked->company_id);
            $resolvedPaymentDate = $paymentDate !== null && $paymentDate !== ''
                ? CarbonImmutable::parse($paymentDate, $timezone)->startOfDay()
                : CarbonImmutable::now($timezone)->startOfDay();

            if ($locked->start_date !== null
                && $resolvedPaymentDate->lt(CarbonImmutable::parse($locked->start_date->toDateString(), $timezone))) {
                throw ValidationException::withMessages([
                    'payment_date' => 'Payment date cannot be before the pay period start date.',
                ]);
            }

            $paidAt = now();
            $files = is_array($proofFiles)
                ? $proofFiles
                : ($proofFiles instanceof UploadedFile ? [$proofFiles] : []);

            $paths = $locked->payment_proof_paths ?? [];
            if ($locked->payment_proof_path !== null && ! in_array($locked->payment_proof_path, $paths, true)) {
                array_unshift($paths, $locked->payment_proof_path);
            }

            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $paths[] = UploadedFileStorage::store($file, 'payroll-periods/payment-proofs');
                }
            }

            $paths = array_values(array_unique($paths));
            $primaryPath = $paths[0] ?? null;

            $locked->payrollRecords()->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $locked->update([
                'status' => PayrollPeriodStatus::Paid,
                'payment_date' => $resolvedPaymentDate->toDateString(),
                'payment_proof_path' => $primaryPath,
                'payment_proof_paths' => $paths,
            ]);

            return $locked->refresh();
        });
    }
}
