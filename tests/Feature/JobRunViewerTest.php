<?php

use App\Models\User;
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

test('guests cannot view job runs page', function () {
    $this->get(route('jobs.index'))->assertRedirect(route('login'));
});

test('authenticated users can view job runs history tab', function () {
    $user = User::factory()->create();

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
            ->has('registry'));
});

test('authenticated users can view failed and pending tabs', function () {
    $user = User::factory()->create();

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

test('authenticated users can retry a failed queue job', function () {
    $user = User::factory()->create();
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
});

test('authenticated users can retry all failed queue jobs', function () {
    $user = User::factory()->create();
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

test('authenticated users can delete a failed queue job', function () {
    $user = User::factory()->create();
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

test('authenticated users can delete all failed queue jobs', function () {
    $user = User::factory()->create();
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

test('authenticated users can delete a job run history record', function () {
    $user = User::factory()->create();
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

    expect(DB::table('job_runs')->whereKey($jobRunId)->exists())->toBeFalse();
});

test('authenticated users can delete all job run history records', function () {
    $user = User::factory()->create();

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

    expect(DB::table('job_runs')->count())->toBe(0);
});

test('authenticated users can delete a pending queue job', function () {
    $user = User::factory()->create();

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

test('authenticated users can delete all pending queue jobs', function () {
    $user = User::factory()->create();

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
});

class TestQueueJobForJobRuns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}
