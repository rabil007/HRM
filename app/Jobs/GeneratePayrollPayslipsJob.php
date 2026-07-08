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

    public int $timeout = 120;

    /**
     * @param  list<int>  $recordIds
     */
    public function __construct(
        public int $companyId,
        public int $periodId,
        public array $recordIds,
    ) {}

    public function handle(
        GeneratePayrollPayslips $generatePayrollPayslips,
        GeneratePayslip $generatePayslip,
    ): void {
        $generatePayrollPayslips->handle(
            $this->companyId,
            $this->periodId,
            $this->recordIds,
            $generatePayslip,
        );
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
