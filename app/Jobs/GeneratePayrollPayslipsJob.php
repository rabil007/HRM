<?php

namespace App\Jobs;

use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GeneratePayslip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePayrollPayslipsJob implements ShouldQueue
{
    use Queueable;

    private const RECORDS_PER_CHUNK = 25;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $periodId,
        public int $companyId,
        public ?int $afterRecordId = null,
    ) {}

    public function handle(GeneratePayslip $generatePayslip): void
    {
        $records = PayrollRecord::query()
            ->where('company_id', $this->companyId)
            ->where('period_id', $this->periodId)
            ->when($this->afterRecordId !== null, function ($query): void {
                $query->where('id', '>', $this->afterRecordId);
            })
            ->orderBy('id')
            ->limit(self::RECORDS_PER_CHUNK)
            ->with('employee')
            ->get();

        foreach ($records as $record) {
            $generatePayslip->handle($record);
        }

        if ($records->count() < self::RECORDS_PER_CHUNK) {
            return;
        }

        $lastRecordId = $records->last()?->id;

        if ($lastRecordId === null) {
            return;
        }

        $hasMore = PayrollRecord::query()
            ->where('company_id', $this->companyId)
            ->where('period_id', $this->periodId)
            ->where('id', '>', $lastRecordId)
            ->exists();

        if ($hasMore) {
            self::dispatch($this->periodId, $this->companyId, $lastRecordId);
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
