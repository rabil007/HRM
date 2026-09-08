<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Mail\FailedQueueJobMail;
use App\Models\JobRun;
use App\Support\Queue\JobRunRecorder;
use App\Support\Queue\ScheduledJobRunRecording;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

afterEach(function () {
    JobRunRecorder::flushScheduledState();
});

function scheduledEventFor(string $artisanCommand): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => ScheduledJobRunRecording::artisanCommandName((string) $event->command) === $artisanCommand);

    expect($event)->not->toBeNull();

    return $event;
}

function recordScheduledSuccess(string $artisanCommand): void
{
    $event = scheduledEventFor($artisanCommand);
    $recorder = app(JobRunRecorder::class);

    $recorder->recordScheduledStarting(new ScheduledTaskStarting($event));
    $recorder->recordScheduledFinished(new ScheduledTaskFinished($event, 0.12));
}

function recordScheduledFailure(string $artisanCommand, Throwable $exception): void
{
    $event = scheduledEventFor($artisanCommand);
    $recorder = app(JobRunRecorder::class);

    $recorder->recordScheduledStarting(new ScheduledTaskStarting($event));
    $recorder->recordScheduledFinished(new ScheduledTaskFinished($event, 0.12));
    $recorder->recordScheduledFailed(new ScheduledTaskFailed($event, $exception));
}

test('failures-only scheduled commands do not keep a successful history row', function (string $command) {
    recordScheduledSuccess($artisanCommand = $command);

    expect(JobRun::withTrashed()->where('type', JobRun::TYPE_SCHEDULED)->count())->toBe(0)
        ->and(ScheduledJobRunRecording::recordsFailuresOnly(scheduledEventFor($artisanCommand)->command))->toBeTrue();
})->with([
    'hikvision reconciliation dispatcher' => 'hikvision:fetch-access-events',
    'hikvision evening dispatcher' => 'hikvision:fetch-todays-access-events',
    'crew alert digest dispatcher' => 'crew:dispatch-operational-alert-email-digests',
]);

test('failures-only scheduled commands keep a failed row with exception details', function () {
    $exception = new RuntimeException('Hikvision scheduler dispatcher failed');

    recordScheduledFailure('hikvision:fetch-access-events', $exception);

    $run = JobRun::query()->where('type', JobRun::TYPE_SCHEDULED)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(JobRun::STATUS_FAILED)
        ->and($run->exception)->toContain('Hikvision scheduler dispatcher failed')
        ->and($run->message)->toContain('Hikvision scheduler dispatcher failed')
        ->and(JobRun::query()->where('status', JobRun::STATUS_COMPLETED)->count())->toBe(0)
        ->and(JobRun::query()->where('status', JobRun::STATUS_RUNNING)->count())->toBe(0);
});

test('failures-only scheduled failures do not send queue alert mail and queue failures still do', function () {
    Mail::fake();

    recordScheduledFailure('hikvision:fetch-access-events', new RuntimeException('Dispatcher failed'));

    Mail::assertNothingSent();

    $uuid = (string) Str::uuid();
    $payload = [
        'uuid' => $uuid,
        'displayName' => FetchHikvisionAccessEventsJob::class,
        'data' => [
            'commandName' => FetchHikvisionAccessEventsJob::class,
        ],
    ];

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn($payload);
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn(json_encode($payload));
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    app(JobRunRecorder::class)->recordQueueFailed(new JobFailed(
        'database',
        $queueJob,
        new RuntimeException('Fetch timed out'),
    ));

    Mail::assertSent(FailedQueueJobMail::class, function (FailedQueueJobMail $mail) use ($uuid): bool {
        return $mail->jobUuid === $uuid
            && str_contains($mail->exceptionSummary, 'Fetch timed out');
    });

    expect(JobRun::query()->where('correlation_id', $uuid)->where('status', JobRun::STATUS_FAILED)->exists())->toBeTrue();
});

test('scheduled commands that perform work are still recorded', function (string $command) {
    recordScheduledSuccess($command);

    $run = JobRun::query()->where('type', JobRun::TYPE_SCHEDULED)->sole();

    expect($run->status)->toBe(JobRun::STATUS_COMPLETED)
        ->and($run->message)->toBe('Scheduled task completed.')
        ->and(ScheduledJobRunRecording::recordsFailuresOnly($run->name))->toBeFalse();
})->with([
    'announcement publish' => 'announcements:publish-scheduled',
    'document recipient email dispatcher' => 'documents:dispatch-recipient-emails',
    'document recipient reconciliation' => 'documents:reconcile-recipient-requests',
    'document lifecycle reconciliation' => 'documents:reconcile-lifecycle-automations',
    'crew operational alert reconciliation' => 'crew:reconcile-operational-alerts',
]);

test('queue jobs remain independently recorded when a failures-only scheduler succeeds', function () {
    recordScheduledSuccess('hikvision:fetch-access-events');

    $uuid = (string) Str::uuid();
    $payload = [
        'uuid' => $uuid,
        'displayName' => FetchHikvisionAccessEventsJob::class,
        'data' => [
            'commandName' => FetchHikvisionAccessEventsJob::class,
        ],
    ];

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn($payload);
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn(json_encode($payload));
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    $recorder = app(JobRunRecorder::class);
    $recorder->recordQueueStarting(new JobProcessing('database', $queueJob));
    $recorder->recordQueueFinished(new JobProcessed('database', $queueJob));

    $run = JobRun::query()->where('correlation_id', $uuid)->first();

    expect(JobRun::query()->where('type', JobRun::TYPE_SCHEDULED)->count())->toBe(0)
        ->and($run)->not->toBeNull()
        ->and($run->type)->toBe(JobRun::TYPE_QUEUE)
        ->and($run->name)->toBe('FetchHikvisionAccessEventsJob')
        ->and($run->status)->toBe(JobRun::STATUS_COMPLETED);
});
