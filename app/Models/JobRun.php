<?php

namespace App\Models;

use App\Support\Queue\JobRunRetention;
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

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $retention = app(JobRunRetention::class);
        $completedCutoff = now()->subDays($retention->completedDays());
        $failedCutoff = now()->subDays($retention->failedDays());
        $runningCutoff = now()->subDays($retention->runningDays());
        $deletedCutoff = now()->subDays($retention->deletedDays());

        return static::query()
            ->withTrashed()
            ->where(function (Builder $query) use ($completedCutoff, $failedCutoff, $runningCutoff, $deletedCutoff): void {
                $query->where(function (Builder $query) use ($completedCutoff): void {
                    $query->whereNull('deleted_at')
                        ->where('status', self::STATUS_COMPLETED)
                        ->where(function (Builder $query) use ($completedCutoff): void {
                            $query->where('finished_at', '<=', $completedCutoff)
                                ->orWhere(function (Builder $query) use ($completedCutoff): void {
                                    $query->whereNull('finished_at')
                                        ->where('created_at', '<=', $completedCutoff);
                                });
                        });
                })->orWhere(function (Builder $query) use ($failedCutoff, $deletedCutoff): void {
                    $query->where('status', self::STATUS_FAILED)
                        ->where(function (Builder $query) use ($failedCutoff): void {
                            $query->where('finished_at', '<=', $failedCutoff)
                                ->orWhere(function (Builder $query) use ($failedCutoff): void {
                                    $query->whereNull('finished_at')
                                        ->where('created_at', '<=', $failedCutoff);
                                });
                        })
                        ->where(function (Builder $query) use ($deletedCutoff): void {
                            $query->whereNull('deleted_at')
                                ->orWhere('deleted_at', '<=', $deletedCutoff);
                        });
                })->orWhere(function (Builder $query) use ($runningCutoff, $deletedCutoff): void {
                    $query->where('status', self::STATUS_RUNNING)
                        ->where('created_at', '<=', $runningCutoff)
                        ->where(function (Builder $query) use ($deletedCutoff): void {
                            $query->whereNull('deleted_at')
                                ->orWhere('deleted_at', '<=', $deletedCutoff);
                        });
                })->orWhere(function (Builder $query) use ($deletedCutoff): void {
                    $query->where('status', self::STATUS_COMPLETED)
                        ->whereNotNull('deleted_at')
                        ->where('deleted_at', '<=', $deletedCutoff);
                });
            });
    }
}
