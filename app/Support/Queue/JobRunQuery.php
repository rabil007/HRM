<?php

namespace App\Support\Queue;

use App\Models\JobRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class JobRunQuery
{
    /** @var list<string> */
    public const HISTORY_STATUSES = [
        JobRun::STATUS_RUNNING,
        JobRun::STATUS_COMPLETED,
        JobRun::STATUS_FAILED,
    ];

    /**
     * @return LengthAwarePaginator<int, JobRun>
     */
    public function paginateHistory(
        ?string $status,
        ?string $name,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $query = JobRun::query()->orderByDesc('started_at');

        if (filled($status) && in_array($status, self::HISTORY_STATUSES, true)) {
            $query->where('status', $status);
        }

        if (filled($name)) {
            $query->where('name', $name);
        }

        if (filled($dateFrom)) {
            $query->whereDate('started_at', '>=', $dateFrom);
        }

        if (filled($dateTo)) {
            $query->whereDate('started_at', '<=', $dateTo);
        }

        if (filled($search)) {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('message', 'like', $needle)
                    ->orWhere('exception', 'like', $needle)
                    ->orWhere('correlation_id', 'like', $needle);
            });
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateFailed(?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('failed_jobs')->orderByDesc('failed_at');

        if (filled($search)) {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('uuid', 'like', $needle)
                    ->orWhere('queue', 'like', $needle)
                    ->orWhere('payload', 'like', $needle)
                    ->orWhere('exception', 'like', $needle);
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->forPage($page, $perPage)
            ->get();

        $items = $rows->map(fn (object $row): array => $this->mapFailedJobRow($row))->values();

        return new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginatePending(?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('jobs')->orderByDesc('created_at');

        if (filled($search)) {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('queue', 'like', $needle)
                    ->orWhere('payload', 'like', $needle);
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->forPage($page, $perPage)
            ->get();

        $items = $rows->map(fn (object $row): array => $this->mapPendingJobRow($row))->values();

        return new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @return list<string>
     */
    public function distinctHistoryNames(): array
    {
        return JobRun::query()
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function mapHistoryRun(JobRun $run): array
    {
        return [
            'id' => $run->id,
            'correlation_id' => $run->correlation_id,
            'type' => $run->type,
            'name' => $run->name,
            'status' => $run->status,
            'queue' => $run->queue,
            'connection' => $run->connection,
            'trigger' => $run->trigger,
            'context' => $run->context,
            'message' => $run->message,
            'exception' => $run->exception,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFailedJobRow(object $row): array
    {
        $payload = json_decode((string) ($row->payload ?? ''), true);
        $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;
        $name = is_string($displayName) ? class_basename($displayName) : 'UnknownJob';
        $exception = (string) ($row->exception ?? '');

        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'name' => $name,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'failed_at' => Carbon::parse((string) $row->failed_at)->toIso8601String(),
            'exception' => $exception,
            'exception_summary' => $this->exceptionSummary($exception),
            'payload' => is_array($payload) ? $payload : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPendingJobRow(object $row): array
    {
        $payload = json_decode((string) ($row->payload ?? ''), true);
        $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;
        $name = is_string($displayName) ? class_basename($displayName) : 'UnknownJob';
        $reservedAt = $row->reserved_at !== null ? (int) $row->reserved_at : null;

        return [
            'id' => (int) $row->id,
            'name' => $name,
            'queue' => (string) $row->queue,
            'attempts' => (int) $row->attempts,
            'reserved_at' => $reservedAt !== null && $reservedAt > 0
                ? Carbon::createFromTimestamp($reservedAt)->toIso8601String()
                : null,
            'available_at' => Carbon::createFromTimestamp((int) $row->available_at)->toIso8601String(),
            'created_at' => Carbon::createFromTimestamp((int) $row->created_at)->toIso8601String(),
            'payload' => is_array($payload) ? $payload : null,
        ];
    }

    private function exceptionSummary(string $exception): string
    {
        $line = strtok($exception, "\n");

        if (! is_string($line) || $line === '') {
            return 'Job failed.';
        }

        return mb_strlen($line) > 255 ? mb_substr($line, 0, 252).'...' : $line;
    }
}
