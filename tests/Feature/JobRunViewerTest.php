<?php

use App\Models\JobRun;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use App\Support\Hikvision\HikvisionEveningAccessEventsFetchSchedule;
use App\Support\Queue\JobRegistry;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

test('guests cannot view job runs page', function () {
    $this->get(route('jobs.index'))->assertRedirect(route('login'));
});

test('authenticated users without platform access cannot view job runs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('jobs.index'))->assertForbidden();
});

test('tenant admins without platform access cannot view or mutate jobs', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'roles.update',
        'settings.application.update',
    ]);

    $this->actingAs($user)->get(route('jobs.index'))->assertForbidden();
    $this->actingAs($user)->post(route('jobs.failed.retry-all'))->assertForbidden();
    $this->actingAs($user)->delete(route('jobs.failed.destroy-all'))->assertForbidden();
});

test('platform viewers can view job runs history tab', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('jobs.index', ['tab' => 'history']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('jobs')
            ->where('tab', 'history')
            ->has('history_runs')
            ->has('pagination')
            ->has('stats')
            ->where('stats.history_count', 0)
            ->where('stats.completed_count', 0)
            ->where('stats.failed_count', 0)
            ->where('stats.pending_count', 0)
            ->where('stats.avg_duration_ms', 0)
            ->where('can.manage', false)
            ->has('registry'));
});

test('job registry schedules reflect configured dispatch times and timezone', function () {
    $timezone = ApplicationTimezone::identifier();
    $entries = collect(JobRegistry::entries());

    $documentCommand = $entries->firstWhere('name', 'documents:dispatch-expiry-alerts');
    $hikvisionFetchCommand = $entries->firstWhere('name', 'hikvision:fetch-access-events');
    $hikvisionEveningCommand = $entries->firstWhere('name', 'hikvision:fetch-todays-access-events');

    expect($documentCommand['schedule'])->toContain($timezone)
        ->and($documentCommand['schedule'])->toContain(DocumentExpiryAlertSchedule::dispatchAt())
        ->and($documentCommand['trigger'])->toContain(DocumentExpiryAlertSchedule::dispatchAt())
        ->and($hikvisionFetchCommand['schedule'])->toContain(HikvisionAccessEventsFetchSchedule::dispatchAt())
        ->and($hikvisionEveningCommand['schedule'])->toContain(HikvisionEveningAccessEventsFetchSchedule::dispatchAt());
});

test('jobs page exposes scheduler timezone to the client', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('jobs.index', ['tab' => 'registry']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scheduler_timezone', ApplicationTimezone::identifier())
            ->has('registry'));
});

test('platform viewers can view failed and pending tabs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('jobs.index', ['tab' => 'failed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'failed')->has('failed_jobs'));

    $this->actingAs($user)
        ->get(route('jobs.index', ['tab' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'pending')->has('pending_jobs'));
});

test('queue job processing creates and completes a job run record', function () {
    $uuid = (string) Str::uuid();

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => TestQueueJobForJobRuns::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => TestQueueJobForJobRuns::class,
            'command' => serialize(new TestQueueJobForJobRuns),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);

    Event::dispatch(new JobProcessing('database', $queueJob));
    Event::dispatch(new JobProcessed('database', $queueJob));

    expect(DB::table('job_runs')->where('correlation_id', $uuid)->value('status'))->toBe('completed');
});

test('queue job processing preserves a custom completion message set during handle', function () {
    $uuid = (string) Str::uuid();

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => TestQueueJobForJobRuns::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => TestQueueJobForJobRuns::class,
            'command' => serialize(new TestQueueJobForJobRuns),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);

    Event::dispatch(new JobProcessing('database', $queueJob));

    DB::table('job_runs')->where('correlation_id', $uuid)->update([
        'message' => 'Fetched 12 access record(s) for today.',
    ]);

    Event::dispatch(new JobProcessed('database', $queueJob));

    expect(DB::table('job_runs')->where('correlation_id', $uuid)->value('message'))
        ->toBe('Fetched 12 access record(s) for today.');
});

test('queue job failure creates failed job run record', function () {
    $uuid = (string) Str::uuid();

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => TestQueueJobForJobRuns::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => TestQueueJobForJobRuns::class,
            'command' => serialize(new TestQueueJobForJobRuns),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    Event::dispatch(new JobProcessing('database', $queueJob));
    Event::dispatch(new JobFailed(
        'database',
        $queueJob,
        new RuntimeException('Test queue failure'),
    ));

    expect(DB::table('job_runs')->where('correlation_id', $uuid)->value('status'))->toBe('failed');
});

test('failed and pending job payloads are not sent to the client', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => TestQueueJobForJobRuns::class,
            'data' => ['secret' => 'should-not-leak'],
        ]),
        'exception' => "RuntimeException: failed\nstack with secrets",
        'failed_at' => now(),
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => TestQueueJobForJobRuns::class,
            'data' => ['token' => 'should-not-leak'],
        ]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->actingAs($user)
        ->get(route('jobs.index', ['tab' => 'failed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('failed_jobs', 1)
            ->where('failed_jobs.0.uuid', $uuid)
            ->has('failed_jobs.0.exception_summary')
            ->missing('failed_jobs.0.payload')
            ->missing('failed_jobs.0.exception'));

    $this->actingAs($user)
        ->get(route('jobs.index', ['tab' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('pending_jobs', 1)
            ->missing('pending_jobs.0.payload'));
});

test('platform viewers cannot retry or delete queue jobs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
        'exception' => 'RuntimeException: failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('jobs.failed.retry', ['uuid' => $uuid]))
        ->assertForbidden();
    $this->actingAs($user)
        ->post(route('jobs.failed.retry-all'))
        ->assertForbidden();
    $this->actingAs($user)
        ->delete(route('jobs.failed.destroy', ['uuid' => $uuid]))
        ->assertForbidden();
    $this->actingAs($user)
        ->delete(route('jobs.failed.destroy-all'))
        ->assertForbidden();
    $this->actingAs($user)
        ->delete(route('jobs.history.destroy-all'))
        ->assertForbidden();
    $this->actingAs($user)
        ->delete(route('jobs.pending.destroy-all'))
        ->assertForbidden();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
});

test('platform managers can retry a failed queue job', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
        'exception' => 'RuntimeException: failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'failed']))
        ->post(route('jobs.failed.retry', ['uuid' => $uuid]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Retried failed queue job')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe($user->id)
        ->and($activity->properties->get('action'))->toBe('platform.jobs.retry_failed')
        ->and($activity->properties->get('uuid'))->toBe($uuid)
        ->and($activity->properties->has('payload'))->toBeFalse();
});

test('platform managers can retry all failed queue jobs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $uuid1 = (string) Str::uuid();
    $uuid2 = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        [
            'uuid' => $uuid1,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
            'exception' => 'RuntimeException: failed 1',
            'failed_at' => now(),
        ],
        [
            'uuid' => $uuid2,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
            'exception' => 'RuntimeException: failed 2',
            'failed_at' => now(),
        ],
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'failed']))
        ->post(route('jobs.failed.retry-all'))
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('platform managers can delete a failed queue job', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
        'exception' => 'RuntimeException: failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'failed']))
        ->delete(route('jobs.failed.destroy', ['uuid' => $uuid]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
});

test('platform managers can delete all failed queue jobs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $uuid1 = (string) Str::uuid();
    $uuid2 = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        [
            'uuid' => $uuid1,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
            'exception' => 'RuntimeException: failed 1',
            'failed_at' => now(),
        ],
        [
            'uuid' => $uuid2,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
            'exception' => 'RuntimeException: failed 2',
            'failed_at' => now(),
        ],
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'failed']))
        ->delete(route('jobs.failed.destroy-all'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('platform managers can delete a job run history record', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $uuid = (string) Str::uuid();

    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => TestQueueJobForJobRuns::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => TestQueueJobForJobRuns::class,
            'command' => serialize(new TestQueueJobForJobRuns),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);

    Event::dispatch(new JobProcessing('database', $queueJob));
    Event::dispatch(new JobProcessed('database', $queueJob));

    $jobRunId = (int) DB::table('job_runs')->where('correlation_id', $uuid)->value('id');

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'history']))
        ->delete(route('jobs.history.destroy', ['jobRun' => $jobRunId]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(JobRun::query()->whereKey($jobRunId)->exists())->toBeFalse();
    $this->assertSoftDeleted('job_runs', ['id' => $jobRunId]);
});

test('platform managers can delete all job run history records', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    DB::table('job_runs')->insert([
        [
            'correlation_id' => (string) Str::uuid(),
            'type' => 'queue',
            'name' => TestQueueJobForJobRuns::class,
            'status' => 'completed',
            'queue' => 'default',
            'connection' => 'database',
            'trigger' => 'system',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'correlation_id' => (string) Str::uuid(),
            'type' => 'queue',
            'name' => TestQueueJobForJobRuns::class,
            'status' => 'failed',
            'queue' => 'default',
            'connection' => 'database',
            'trigger' => 'system',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'history']))
        ->delete(route('jobs.history.destroy-all'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(JobRun::query()->count())->toBe(0)
        ->and(JobRun::onlyTrashed()->count())->toBe(2);
});

test('platform managers can delete a pending queue job', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $jobId = DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'pending']))
        ->delete(route('jobs.pending.destroy', ['id' => $jobId]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DB::table('jobs')->where('id', $jobId)->exists())->toBeFalse();
});

test('platform managers can delete all pending queue jobs', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
        [
            'queue' => 'default',
            'payload' => json_encode(['displayName' => TestQueueJobForJobRuns::class]),
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
    ]);

    $this->actingAs($user)
        ->from(route('jobs.index', ['tab' => 'pending']))
        ->delete(route('jobs.pending.destroy-all'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DB::table('jobs')->count())->toBe(0);

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Cleared pending queue jobs')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('action'))->toBe('platform.jobs.destroy_all_pending');
});

class TestQueueJobForJobRuns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}
