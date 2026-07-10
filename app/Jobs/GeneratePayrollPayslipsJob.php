<?php

namespace App\Jobs;

use App\Support\Payroll\Actions\GeneratePayrollPayslips;
use App\Support\Payroll\Actions\GeneratePayslip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePayrollPayslipsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @param  list<int>  $recordIds
     */
    public function __construct(
        public int $companyId,
        public int $periodId,
        public array $recordIds,
    ) {
        $this->onQueue('payroll');
    }

    public function handle(
        GeneratePayrollPayslips $generatePayrollPayslips,
        GeneratePayslip $generatePayslip,
    ): void {
        // #region agent log
        $handleStartedAt = microtime(true);
        $queueJob = $this->job;
        $availableAt = null;
        $attempts = null;
        $queueName = null;
        try {
            $availableAt = method_exists($queueJob, 'availableAt') ? $queueJob->availableAt() : null;
            $attempts = method_exists($queueJob, 'attempts') ? $queueJob->attempts() : null;
            $queueName = method_exists($queueJob, 'getQueue') ? $queueJob->getQueue() : null;
        } catch (Throwable) {
        }
        $waitSeconds = is_numeric($availableAt)
            ? max(0, (int) round($handleStartedAt - (float) $availableAt))
            : null;
        @file_put_contents(base_path('.cursor/debug-ed6c6d.log'), json_encode([
            'sessionId' => 'ed6c6d',
            'runId' => 'post-fix',
            'hypothesisId' => 'A,B,D,E',
            'location' => 'GeneratePayrollPayslipsJob.php:handle:start',
            'message' => 'Payslip job started',
            'data' => [
                'companyId' => $this->companyId,
                'periodId' => $this->periodId,
                'recordCount' => count($this->recordIds),
                'timeout' => $this->timeout,
                'tries' => $this->tries,
                'queueConnection' => (string) config('queue.default'),
                'dbRetryAfter' => (int) config('queue.connections.database.retry_after'),
                'configuredQueue' => $this->queue,
                'queueName' => $queueName,
                'availableAt' => $availableAt,
                'attempts' => $attempts,
                'queueWaitSeconds' => $waitSeconds,
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        $generatePayrollPayslips->handle(
            $this->companyId,
            $this->periodId,
            $this->recordIds,
            $generatePayslip,
        );

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-ed6c6d.log'), json_encode([
            'sessionId' => 'ed6c6d',
            'runId' => 'post-fix',
            'hypothesisId' => 'D',
            'location' => 'GeneratePayrollPayslipsJob.php:handle:end',
            'message' => 'Payslip job finished',
            'data' => [
                'companyId' => $this->companyId,
                'periodId' => $this->periodId,
                'recordCount' => count($this->recordIds),
                'durationMs' => (int) round((microtime(true) - $handleStartedAt) * 1000),
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
