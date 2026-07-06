<?php

namespace App\Support\BulkDocuments;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class SalaryDeclarationGenerationProgress
{
    private const CACHE_TTL_HOURS = 2;

    private const DISPLAY_TTL_MINUTES = 10;

    /**
     * @return array{
     *     status: 'idle'|'running'|'completed'|'failed',
     *     message: string|null,
     *     generated: int,
     *     skipped: int,
     *     started_at: string|null,
     *     finished_at: string|null
     * }
     */
    public static function forCompany(int $companyId): array
    {
        $stored = Cache::get(self::cacheKey($companyId));

        if (! is_array($stored)) {
            return self::idle();
        }

        $status = is_string($stored['status'] ?? null) ? $stored['status'] : 'idle';

        if (in_array($status, ['completed', 'failed'], true)) {
            $finishedAt = is_string($stored['finished_at'] ?? null)
                ? Carbon::parse($stored['finished_at'])
                : null;

            if ($finishedAt !== null && $finishedAt->lt(now()->subMinutes(self::DISPLAY_TTL_MINUTES))) {
                Cache::forget(self::cacheKey($companyId));

                return self::idle();
            }
        }

        return [
            'status' => in_array($status, ['running', 'completed', 'failed'], true) ? $status : 'idle',
            'message' => is_string($stored['message'] ?? null) ? $stored['message'] : null,
            'generated' => (int) ($stored['generated'] ?? 0),
            'skipped' => (int) ($stored['skipped'] ?? 0),
            'started_at' => is_string($stored['started_at'] ?? null) ? $stored['started_at'] : null,
            'finished_at' => is_string($stored['finished_at'] ?? null) ? $stored['finished_at'] : null,
        ];
    }

    public static function markQueued(int $companyId): void
    {
        self::write($companyId, [
            'status' => 'running',
            'message' => 'Salary declaration generation queued...',
            'generated' => 0,
            'skipped' => 0,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);
    }

    public static function markRunning(int $companyId, string $batchCorrelationId): void
    {
        $current = Cache::get(self::cacheKey($companyId));
        $startedAt = is_array($current) && is_string($current['started_at'] ?? null)
            ? $current['started_at']
            : now()->toIso8601String();

        self::write($companyId, [
            'status' => 'running',
            'batch_correlation_id' => $batchCorrelationId,
            'message' => 'Generating salary declarations...',
            'generated' => is_array($current) ? (int) ($current['generated'] ?? 0) : 0,
            'skipped' => is_array($current) ? (int) ($current['skipped'] ?? 0) : 0,
            'started_at' => $startedAt,
            'finished_at' => null,
        ]);
    }

    /**
     * @param  array{
     *     status: 'running'|'completed'|'failed',
     *     message: string,
     *     generated: int,
     *     skipped: int,
     *     finished_at?: string|null
     * }  $data
     */
    public static function update(int $companyId, array $data): void
    {
        $current = Cache::get(self::cacheKey($companyId));
        $startedAt = is_array($current) && is_string($current['started_at'] ?? null)
            ? $current['started_at']
            : now()->toIso8601String();

        self::write($companyId, array_merge(
            is_array($current) ? $current : [],
            $data,
            ['started_at' => $startedAt],
        ));
    }

    public static function markFailed(int $companyId, string $message): void
    {
        self::update($companyId, [
            'status' => 'failed',
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{
     *     status: 'idle',
     *     message: null,
     *     generated: int,
     *     skipped: int,
     *     started_at: null,
     *     finished_at: null
     * }
     */
    private static function idle(): array
    {
        return [
            'status' => 'idle',
            'message' => null,
            'generated' => 0,
            'skipped' => 0,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function write(int $companyId, array $data): void
    {
        Cache::put(
            self::cacheKey($companyId),
            $data,
            now()->addHours(self::CACHE_TTL_HOURS),
        );
    }

    private static function cacheKey(int $companyId): string
    {
        return "salary-declarations-generation:{$companyId}";
    }
}
