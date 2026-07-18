<?php

use App\Models\JobRun;
use Illuminate\Support\Facades\Artisan;

function createRetentionJobRun(array $overrides = []): JobRun
{
    return JobRun::query()->create([
        'correlation_id' => fake()->uuid(),
        'type' => JobRun::TYPE_QUEUE,
        'name' => 'retention-test',
        'status' => JobRun::STATUS_COMPLETED,
        'trigger' => JobRun::TRIGGER_SYSTEM,
        'started_at' => now(),
        'finished_at' => now(),
        'duration_ms' => 100,
        ...$overrides,
    ]);
}

test('job run pruning removes records older than configured retention', function () {
    config()->set('queue.job_run_retention_days', 30);

    $expired = createRetentionJobRun([
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);

    $retained = createRetentionJobRun([
        'created_at' => now()->subDays(29),
        'updated_at' => now()->subDays(29),
    ]);

    Artisan::call('model:prune', [
        '--model' => [JobRun::class],
    ]);

    expect(JobRun::withTrashed()->find($expired->id))->toBeNull()
        ->and(JobRun::query()->find($retained->id))->not->toBeNull();
});

test('job run pruning permanently removes expired soft deleted records', function () {
    config()->set('queue.job_run_retention_days', 30);

    $expired = createRetentionJobRun([
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);
    $expired->delete();

    Artisan::call('model:prune', [
        '--model' => [JobRun::class],
    ]);

    expect(JobRun::withTrashed()->find($expired->id))->toBeNull();
});
