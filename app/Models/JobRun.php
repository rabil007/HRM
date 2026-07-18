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
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $retentionDays = max(1, (int) config('queue.job_run_retention_days', 90));

        return self::withTrashed()
            ->where('created_at', '<=', now()->subDays($retentionDays));
    }

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
}
