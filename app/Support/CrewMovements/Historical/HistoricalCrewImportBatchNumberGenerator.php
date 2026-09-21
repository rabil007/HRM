<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\HistoricalCrewImportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HistoricalCrewImportBatchNumberGenerator
{
    public function next(int $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                try {
                    $last = HistoricalCrewImportBatch::query()
                        ->where('company_id', $companyId)
                        ->lockForUpdate()
                        ->orderByDesc('id')
                        ->value('batch_no');

                    $next = 1;

                    if (is_string($last) && preg_match('/^HI-(\d+)$/', $last, $matches) === 1) {
                        $next = ((int) $matches[1]) + 1;
                    }

                    return sprintf('HI-%06d', $next);
                } catch (QueryException) {
                    // Concurrent lock contention — retry.
                }
            }

            throw new RuntimeException('Unable to allocate a historical import batch number.');
        });
    }
}
