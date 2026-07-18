<?php

use App\Models\JobRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

afterEach(function () {
    Carbon::setTestNow();
});

function makeJobRun(array $attributes = []): JobRun
{
    return JobRun::query()->create(array_merge([
        'type' => JobRun::TYPE_QUEUE,
        'name' => 'App\\Jobs\\ExampleJob',
        'status' => JobRun::STATUS_COMPLETED,
        'trigger' => JobRun::TRIGGER_SYSTEM,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinutes(2),
        'duration_ms' => 120_000,
    ], $attributes));
}

test('job run retention cannot be configured below one day', function () {
    config()->set('queue.job_run_retention_days', 0);

    expect(JobRun::retentionDays())->toBe(1);

    config()->set('queue.job_run_retention_days', -5);

    expect(JobRun::retentionDays())->toBe(1);

    config()->set('queue.job_run_retention_days', 14);

    expect(JobRun::retentionDays())->toBe(14);
});

test('pruning permanently removes old completed and soft-deleted job runs while keeping recent ones', function () {
    config()->set('queue.job_run_retention_days', 30);
    Carbon::setTestNow('2026-07-18 12:00:00');

    $oldCompleted = makeJobRun([
        'name' => 'old-completed',
        'status' => JobRun::STATUS_COMPLETED,
    ]);
    JobRun::query()->whereKey($oldCompleted->id)->update([
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(40),
        'started_at' => now()->subDays(40),
        'finished_at' => now()->subDays(40),
    ]);

    $oldFailed = makeJobRun([
        'name' => 'old-failed',
        'status' => JobRun::STATUS_FAILED,
    ]);
    JobRun::query()->whereKey($oldFailed->id)->update([
        'created_at' => now()->subDays(35),
        'updated_at' => now()->subDays(35),
        'started_at' => now()->subDays(35),
        'finished_at' => now()->subDays(35),
    ]);

    $oldSoftDeleted = makeJobRun([
        'name' => 'old-soft-deleted',
        'status' => JobRun::STATUS_COMPLETED,
    ]);
    $oldSoftDeleted->delete();
    JobRun::withTrashed()->whereKey($oldSoftDeleted->id)->update([
        'deleted_at' => now()->subDays(40),
    ]);

    $recentCompleted = makeJobRun([
        'name' => 'recent-completed',
        'status' => JobRun::STATUS_COMPLETED,
    ]);
    JobRun::query()->whereKey($recentCompleted->id)->update([
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
        'started_at' => now()->subDays(2),
        'finished_at' => now()->subDays(2),
    ]);

    $recentSoftDeleted = makeJobRun([
        'name' => 'recent-soft-deleted',
        'status' => JobRun::STATUS_FAILED,
    ]);
    $recentSoftDeleted->delete();

    $recentRunning = makeJobRun([
        'name' => 'recent-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($oldCompleted->id))->toBeNull()
        ->and(JobRun::withTrashed()->find($oldFailed->id))->toBeNull()
        ->and(JobRun::withTrashed()->find($oldSoftDeleted->id))->toBeNull()
        ->and(JobRun::query()->find($recentCompleted->id))->not->toBeNull()
        ->and(JobRun::withTrashed()->find($recentSoftDeleted->id)?->trashed())->toBeTrue()
        ->and(JobRun::query()->find($recentRunning->id))->not->toBeNull();
});

test('pruning removes stuck running job runs older than retention but keeps recent running jobs', function () {
    config()->set('queue.job_run_retention_days', 30);
    Carbon::setTestNow('2026-07-18 12:00:00');

    $stuckRunning = makeJobRun([
        'name' => 'stuck-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);
    JobRun::query()->whereKey($stuckRunning->id)->update([
        'created_at' => now()->subDays(45),
        'updated_at' => now()->subDays(45),
        'started_at' => now()->subDays(45),
        'finished_at' => null,
    ]);

    $activeRunning = makeJobRun([
        'name' => 'active-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($stuckRunning->id))->toBeNull()
        ->and(JobRun::query()->find($activeRunning->id))->not->toBeNull();
});

test('completed jobs are pruned by finished_at not created_at when finished recently', function () {
    config()->set('queue.job_run_retention_days', 30);
    Carbon::setTestNow('2026-07-18 12:00:00');

    $longLivedButRecentlyFinished = makeJobRun([
        'name' => 'long-lived-recent-finish',
        'status' => JobRun::STATUS_COMPLETED,
    ]);
    JobRun::query()->whereKey($longLivedButRecentlyFinished->id)->update([
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(2),
        'started_at' => now()->subDays(60),
        'finished_at' => now()->subDays(2),
    ]);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::query()->find($longLivedButRecentlyFinished->id))->not->toBeNull();
});

test('job run pruning is scheduled daily in the application timezone without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'model:prune')
            && str_contains((string) $event->command, 'JobRun'));

    expect($event)->not->toBeNull()
        ->and($event->description)->toBe('job-runs-prune')
        ->and((string) $event->timezone)->toBe((string) config('app.timezone', 'UTC'))
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expression)->toBe('0 2 * * *');
});
