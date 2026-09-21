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
                } catch (QueryException $exception) {
                    if (! $this->isTransientLockException($exception)) {
                        throw $exception;
                    }
                }
            }

            throw new RuntimeException('Unable to allocate a historical import batch number.');
        });
    }

    /**
     * Retry only known transient lock contention; rethrow everything else.
     */
    public function isTransientLockException(QueryException $exception): bool
    {
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        // MySQL / MariaDB: deadlock / lock wait timeout
        if (in_array($driverCode, ['1213', '1205'], true)) {
            return true;
        }

        if (in_array($sqlState, ['40001', 'HY000'], true)
            && (str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout'))) {
            return true;
        }

        // SQLite busy / locked (test database)
        if (str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'database schema is locked')) {
            return true;
        }

        return false;
    }
}
