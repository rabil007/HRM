<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApprovePayrollPeriod
{
    public function handle(PayrollPeriod $period, User $approver): PayrollPeriod
    {
        if (! $period->canApprove()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only processing pay periods with generated payroll records can be approved.',
            ]);
        }

        $approvedPeriod = DB::transaction(function () use ($period, $approver): PayrollPeriod {
            $period->payrollRecords()->update(['status' => 'approved']);

            $period->update([
                'status' => PayrollPeriodStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $period->refresh();
        });

        // #region agent log
        $dispatchStartedAt = microtime(true);
        // #endregion

        app(GeneratePayrollPayslips::class)->dispatchForPeriod($approvedPeriod);

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-ed6c6d.log'), json_encode([
            'sessionId' => 'ed6c6d',
            'runId' => 'post-fix',
            'hypothesisId' => 'C,E',
            'location' => 'ApprovePayrollPeriod.php:handle',
            'message' => 'Approve completed and payslip dispatch returned',
            'data' => [
                'periodId' => (int) $approvedPeriod->id,
                'companyId' => (int) $approvedPeriod->company_id,
                'dispatchElapsedMs' => (int) round((microtime(true) - $dispatchStartedAt) * 1000),
                'queueConnection' => (string) config('queue.default'),
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        return $approvedPeriod;
    }
}
