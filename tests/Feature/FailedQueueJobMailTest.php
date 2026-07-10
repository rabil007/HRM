<?php

use App\Jobs\SyncHikvisionAttendanceJob;
use App\Mail\FailedQueueJobMail;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('failed queue jobs send an alert email to the configured recipient', function () {
    Mail::fake();

    $uuid = (string) Str::uuid();
    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => 'App\\Jobs\\SyncHikvisionAttendanceJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => 'App\\Jobs\\SyncHikvisionAttendanceJob',
            'command' => serialize(new SyncHikvisionAttendanceJob),
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    Event::dispatch(new JobFailed(
        'database',
        $queueJob,
        new RuntimeException('Sync timed out'),
    ));

    Mail::assertSent(FailedQueueJobMail::class, function (FailedQueueJobMail $mail) use ($uuid): bool {
        return $mail->hasTo('rabil@overseas-ms.com')
            && $mail->jobName === 'SyncHikvisionAttendanceJob'
            && $mail->queueName === 'default'
            && $mail->queueConnection === 'database'
            && $mail->jobUuid === $uuid
            && str_contains($mail->exceptionSummary, 'Sync timed out');
    });
});

test('failed queue job alert can be disabled with an empty recipient', function () {
    Mail::fake();
    config(['queue.failed_job_alert_email' => '']);

    $uuid = (string) Str::uuid();
    $payload = json_encode([
        'uuid' => $uuid,
        'displayName' => 'App\\Jobs\\GeneratePayrollPayslipsJob',
        'data' => [
            'commandName' => 'App\\Jobs\\GeneratePayrollPayslipsJob',
        ],
    ]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('payload')->andReturn(json_decode($payload, true));
    $queueJob->shouldReceive('getQueue')->andReturn('default');
    $queueJob->shouldReceive('getRawBody')->andReturn($payload);
    $queueJob->shouldReceive('uuid')->andReturn($uuid);

    Event::dispatch(new JobFailed(
        'database',
        $queueJob,
        new RuntimeException('Payslip failed'),
    ));

    Mail::assertNothingSent();
});
