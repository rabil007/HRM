<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobRun extends Model
{
    use MassPrunable;
    use SoftDeletes;

    public const TYPE_QUEUE = 'queue';

    public const TYPE_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SYSTEM = 'system';

    protected $fillable = [
        'correlation_id',
        'type',
        'name',
        'status',
        'queue',
        'connection',
        'trigger',
        'context',
        'message',
        'exception',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public static function retentionDays(): int
    {
        return max(1, (int) config('queue.job_run_retention_days', 90));
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::retentionDays());

        return static::query()
            ->withTrashed()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where(function (Builder $query) use ($cutoff): void {
                    $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED])
                        ->where(function (Builder $query) use ($cutoff): void {
                            $query->where('finished_at', '<=', $cutoff)
                                ->orWhere(function (Builder $query) use ($cutoff): void {
                                    $query->whereNull('finished_at')
                                        ->where('created_at', '<=', $cutoff);
                                });
                        });
                })->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->where('status', self::STATUS_RUNNING)
                        ->where('created_at', '<=', $cutoff);
                })->orWhere(function (Builder $query) use ($cutoff): void {
                    $query->whereNotNull('deleted_at')
                        ->where('deleted_at', '<=', $cutoff);
                });
            });
    }
}
