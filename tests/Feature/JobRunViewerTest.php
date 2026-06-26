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
            ->has('pagination'));
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

class TestQueueJobForJobRuns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}
