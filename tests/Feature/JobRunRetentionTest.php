<?php

use App\Models\JobRun;
use App\Models\User;
use App\Services\Settings\SettingService;
use App\Support\Queue\JobRunRetention;
use App\Support\Settings\SettingKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

afterEach(function () {
    Carbon::setTestNow();
    app(SettingService::class)->clearCache();
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

function ageJobRun(JobRun $run, int $days, bool $clearFinishedAt = false): void
{
    JobRun::withTrashed()->whereKey($run->id)->update([
        'created_at' => now()->subDays($days),
        'updated_at' => now()->subDays($days),
        'started_at' => now()->subDays($days),
        'finished_at' => $clearFinishedAt ? null : now()->subDays($days),
    ]);
}

test('job run retention clamps stored platform settings to a safe range', function () {
    $settings = app(SettingService::class);

    $settings->setMany([
        SettingKey::JobRunCompletedRetentionDays => '0',
        SettingKey::JobRunFailedRetentionDays => '-5',
        SettingKey::JobRunRunningRetentionDays => '0',
        SettingKey::JobRunDeletedRetentionDays => '99999',
    ]);

    $retention = app(JobRunRetention::class);

    expect($retention->completedDays())->toBe(1)
        ->and($retention->failedDays())->toBe(1)
        ->and($retention->runningDays())->toBe(1)
        ->and($retention->deletedDays())->toBe(JobRunRetention::MAX_DAYS);

    $settings->setMany([
        SettingKey::JobRunCompletedRetentionDays => '14',
        SettingKey::JobRunFailedRetentionDays => '45',
        SettingKey::JobRunRunningRetentionDays => '21',
        SettingKey::JobRunDeletedRetentionDays => '7',
    ]);

    $retention = app(JobRunRetention::class);

    expect($retention->completedDays())->toBe(14)
        ->and($retention->failedDays())->toBe(45)
        ->and($retention->runningDays())->toBe(21)
        ->and($retention->deletedDays())->toBe(7);
});

test('completed jobs older than the completed retention are pruned while recent completed jobs remain', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $oldCompleted = makeJobRun(['name' => 'old-completed']);
    ageJobRun($oldCompleted, 31);

    $recentCompleted = makeJobRun(['name' => 'recent-completed']);
    ageJobRun($recentCompleted, 2);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($oldCompleted->id))->toBeNull()
        ->and(JobRun::query()->find($recentCompleted->id))->not->toBeNull();
});

test('failed jobs use the longer failed retention', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $oldFailed = makeJobRun([
        'name' => 'old-failed',
        'status' => JobRun::STATUS_FAILED,
    ]);
    ageJobRun($oldFailed, 91);

    $retainedFailed = makeJobRun([
        'name' => 'retained-failed',
        'status' => JobRun::STATUS_FAILED,
    ]);
    ageJobRun($retainedFailed, 45);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($oldFailed->id))->toBeNull()
        ->and(JobRun::query()->find($retainedFailed->id))->not->toBeNull();
});

test('stale running jobs are pruned only after the running retention', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $stuckRunning = makeJobRun([
        'name' => 'stuck-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);
    ageJobRun($stuckRunning, 91, clearFinishedAt: true);

    $recentRunning = makeJobRun([
        'name' => 'recent-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);

    $midRunning = makeJobRun([
        'name' => 'mid-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);
    ageJobRun($midRunning, 45, clearFinishedAt: true);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($stuckRunning->id))->toBeNull()
        ->and(JobRun::query()->find($recentRunning->id))->not->toBeNull()
        ->and(JobRun::query()->find($midRunning->id))->not->toBeNull();
});

test('soft-deleted completed history follows deleted retention without overriding failed retention', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $oldSoftDeleted = makeJobRun(['name' => 'old-soft-deleted']);
    $oldSoftDeleted->delete();
    JobRun::withTrashed()->whereKey($oldSoftDeleted->id)->update([
        'deleted_at' => now()->subDays(31),
    ]);

    $recentSoftDeleted = makeJobRun([
        'name' => 'recent-soft-deleted',
        'status' => JobRun::STATUS_FAILED,
    ]);
    $recentSoftDeleted->delete();

    $softDeletedFailed = makeJobRun([
        'name' => 'soft-deleted-failed',
        'status' => JobRun::STATUS_FAILED,
    ]);
    ageJobRun($softDeletedFailed, 45);
    $softDeletedFailed->delete();
    JobRun::withTrashed()->whereKey($softDeletedFailed->id)->update([
        'deleted_at' => now()->subDays(40),
    ]);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($oldSoftDeleted->id))->toBeNull()
        ->and(JobRun::withTrashed()->find($recentSoftDeleted->id)?->trashed())->toBeTrue()
        ->and(JobRun::withTrashed()->find($softDeletedFailed->id)?->trashed())->toBeTrue();
});

test('custom job run retention values are respected', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');
    app(SettingService::class)->setMany([
        SettingKey::JobRunCompletedRetentionDays => '7',
        SettingKey::JobRunFailedRetentionDays => '14',
        SettingKey::JobRunRunningRetentionDays => '10',
        SettingKey::JobRunDeletedRetentionDays => '5',
    ]);

    $completed = makeJobRun(['name' => 'custom-completed']);
    ageJobRun($completed, 8);

    $failed = makeJobRun([
        'name' => 'custom-failed',
        'status' => JobRun::STATUS_FAILED,
    ]);
    ageJobRun($failed, 15);

    $running = makeJobRun([
        'name' => 'custom-running',
        'status' => JobRun::STATUS_RUNNING,
        'finished_at' => null,
    ]);
    ageJobRun($running, 11, clearFinishedAt: true);

    $keptFailed = makeJobRun([
        'name' => 'custom-kept-failed',
        'status' => JobRun::STATUS_FAILED,
    ]);
    ageJobRun($keptFailed, 10);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($completed->id))->toBeNull()
        ->and(JobRun::withTrashed()->find($failed->id))->toBeNull()
        ->and(JobRun::withTrashed()->find($running->id))->toBeNull()
        ->and(JobRun::query()->find($keptFailed->id))->not->toBeNull();
});

test('completed and failed jobs fall back to created_at when finished_at is null', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $completed = makeJobRun([
        'name' => 'completed-without-finished-at',
        'status' => JobRun::STATUS_COMPLETED,
        'finished_at' => null,
    ]);
    ageJobRun($completed, 31, clearFinishedAt: true);

    $failed = makeJobRun([
        'name' => 'failed-without-finished-at',
        'status' => JobRun::STATUS_FAILED,
        'finished_at' => null,
    ]);
    ageJobRun($failed, 91, clearFinishedAt: true);

    $recentCompleted = makeJobRun([
        'name' => 'recent-completed-without-finished-at',
        'status' => JobRun::STATUS_COMPLETED,
        'finished_at' => null,
    ]);
    ageJobRun($recentCompleted, 5, clearFinishedAt: true);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($completed->id))->toBeNull()
        ->and(JobRun::withTrashed()->find($failed->id))->toBeNull()
        ->and(JobRun::query()->find($recentCompleted->id))->not->toBeNull();
});

test('completed jobs are pruned by finished_at not created_at when finished recently', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $longLivedButRecentlyFinished = makeJobRun(['name' => 'long-lived-recent-finish']);
    JobRun::query()->whereKey($longLivedButRecentlyFinished->id)->update([
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(2),
        'started_at' => now()->subDays(60),
        'finished_at' => now()->subDays(2),
    ]);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::query()->find($longLivedButRecentlyFinished->id))->not->toBeNull();
});

test('job run pruning does not remove unrelated models', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $user = User::factory()->create();
    $oldCompleted = makeJobRun(['name' => 'old-completed']);
    ageJobRun($oldCompleted, 40);

    Artisan::call('model:prune', ['--model' => [JobRun::class]]);

    expect(JobRun::withTrashed()->find($oldCompleted->id))->toBeNull()
        ->and(User::query()->find($user->id))->not->toBeNull();
});

test('job run pruning is scheduled daily in the application timezone without overlapping', function () {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(fn ($event): bool => str_contains((string) $event->command, 'model:prune')
        && str_contains((string) $event->command, 'JobRun'));

    $activityCleanup = $events->first(fn ($event): bool => str_contains((string) $event->command, 'activitylog:clean'));

    expect($event)->not->toBeNull()
        ->and($event->description)->toBe('job-runs-prune')
        ->and((string) $event->timezone)->toBe((string) config('app.timezone', 'UTC'))
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($activityCleanup)->toBeNull();
});
