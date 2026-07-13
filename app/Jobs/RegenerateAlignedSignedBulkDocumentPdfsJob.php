<?php

namespace App\Jobs;

use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\BulkDocuments\RegenerateSignedBulkDocumentPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RegenerateAlignedSignedBulkDocumentPdfsJob implements ShouldQueue
{
    use Queueable;

    private const REQUESTS_PER_CHUNK = 10;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<int>  $requestIds
     */
    public function __construct(
        public int $companyId,
        public ?int $userId,
        public int $repairRunId,
        public array $requestIds,
        public int $offset = 0,
        public int $cumulativeRepaired = 0,
        public int $cumulativeSkipped = 0,
        public int $cumulativeFailed = 0,
    ) {}

    public function handle(RegenerateSignedBulkDocumentPdf $regenerator): void
    {
        $run = BulkDocumentSignatureRepairRun::query()->find($this->repairRunId);

        if ($run === null) {
            return;
        }

        if ($this->offset === 0) {
            $run->update([
                'status' => 'running',
                'started_at' => now(),
            ]);
        }

        $chunkIds = array_slice($this->requestIds, $this->offset, self::REQUESTS_PER_CHUNK);

        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        $requests = BulkDocumentSignatureRequest::query()
            ->where('company_id', $this->companyId)
            ->whereKey($chunkIds)
            ->with(['employee', 'employeeDocument'])
            ->get()
            ->keyBy('id');

        foreach ($chunkIds as $requestId) {
            $request = $requests->get($requestId);

            if ($request === null) {
                $skipped++;

                continue;
            }

            $result = $regenerator->handle($request, forceTemplateRender: true);

            match ($result) {
                'repaired' => $repaired++,
                'skipped' => $skipped++,
                'failed' => $failed++,
            };
        }

        $totalRepaired = $this->cumulativeRepaired + $repaired;
        $totalSkipped = $this->cumulativeSkipped + $skipped;
        $totalFailed = $this->cumulativeFailed + $failed;
        $nextOffset = $this->offset + count($chunkIds);

        $run->update([
            'repaired_count' => $totalRepaired,
            'skipped_count' => $totalSkipped,
            'failed_count' => $totalFailed,
        ]);

        if ($nextOffset < count($this->requestIds)) {
            self::dispatch(
                $this->companyId,
                $this->userId,
                $this->repairRunId,
                $this->requestIds,
                $nextOffset,
                $totalRepaired,
                $totalSkipped,
                $totalFailed,
            );

            return;
        }

        $run->update([
            'status' => $totalFailed > 0 && $totalRepaired === 0 ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        BulkDocumentSignatureRepairRun::query()
            ->where('id', $this->repairRunId)
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

        report($exception);
    }
}
