<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Jobs\GeneratePayrollPayslipsJob;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApprovePayrollPeriod
{
    public function handle(PayrollPeriod $period, User $approver): PayrollPeriod
    {
        // #region agent log
        $approveStartedAt = microtime(true);
        $debugLog = static function (string $hypothesisId, string $location, string $message, array $data = []): void {
            file_put_contents(
                '/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-386635.log',
                json_encode([
                    'sessionId' => '386635',
                    'hypothesisId' => $hypothesisId,
                    'location' => $location,
                    'message' => $message,
                    'data' => $data,
                    'timestamp' => (int) (microtime(true) * 1000),
                ])."\n",
                FILE_APPEND,
            );
        };
        $recordCount = $period->payrollRecords()->count();
        $debugLog('B', 'ApprovePayrollPeriod.php:handle', 'approve_started', [
            'period_id' => $period->id,
            'record_count' => $recordCount,
        ]);
        // #endregion

        if (! $period->canApprove()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only processing pay periods with generated payroll records can be approved.',
            ]);
        }

        $approvedPeriod = DB::transaction(function () use ($period, $approver, $debugLog): PayrollPeriod {
            $statusUpdateStartedAt = microtime(true);

            $period->payrollRecords()->update(['status' => 'approved']);

            $period->update([
                'status' => PayrollPeriodStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            // #region agent log
            $debugLog('B', 'ApprovePayrollPeriod.php:transaction', 'status_updates_completed', [
                'period_id' => $period->id,
                'duration_ms' => (int) round((microtime(true) - $statusUpdateStartedAt) * 1000),
            ]);
            // #endregion

            return $period->refresh();
        });

        GeneratePayrollPayslipsJob::dispatch($approvedPeriod->id, (int) $approvedPeriod->company_id);

        // #region agent log
        $debugLog('A', 'ApprovePayrollPeriod.php:handle', 'payslip_generation_queued', [
            'period_id' => $approvedPeriod->id,
            'record_count' => $recordCount,
            'total_approve_ms' => (int) round((microtime(true) - $approveStartedAt) * 1000),
        ]);
        // #endregion

        return $approvedPeriod;
    }
}
