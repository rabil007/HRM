<?php

namespace App\Support\Queue;

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\JobRun;
use Carbon\CarbonInterface;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

final class JobRunRecorder
{
    /** @var array<int, int> */
    private static array $scheduledRunIds = [];

    public function recordQueueStarting(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $uuid = is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null;
        $name = $this->resolveQueueJobName($payload);
        $context = $this->resolveQueueContext($payload);

        JobRun::query()->create([
            'correlation_id' => $uuid,
            'type' => JobRun::TYPE_QUEUE,
            'name' => $name,
            'status' => JobRun::STATUS_RUNNING,
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'trigger' => $this->resolveQueueTrigger($name, $context),
            'context' => $context === [] ? null : $context,
            'started_at' => now(),
        ]);
    }

    public function recordQueueFinished(JobProcessed $event): void
    {
        $payload = $event->job->payload();
        $uuid = is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null;

        $run = $this->findRunningQueueRun($uuid, $this->resolveQueueJobName($payload));

        if ($run === null) {
            return;
        }

        $finishedAt = now();
        $durationMs = $this->durationMs($run->started_at, $finishedAt);

        $run->update([
            'status' => JobRun::STATUS_COMPLETED,
            'message' => 'Completed successfully.',
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
        ]);
    }

    public function recordQueueFailed(JobFailed $event): void
    {
        $payload = $event->job->payload();
        $uuid = is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null;
        $name = $this->resolveQueueJobName($payload);

        $run = $this->findRunningQueueRun($uuid, $name);

        $finishedAt = now();
        $exception = (string) $event->exception;

        if ($run === null) {
            JobRun::query()->create([
                'correlation_id' => $uuid,
                'type' => JobRun::TYPE_QUEUE,
                'name' => $name,
                'status' => JobRun::STATUS_FAILED,
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'trigger' => $this->resolveQueueTrigger($name, $this->resolveQueueContext($payload)),
                'context' => ($context = $this->resolveQueueContext($payload)) === [] ? null : $context,
                'message' => $this->exceptionSummary($exception),
                'exception' => $exception,
                'started_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => 0,
            ]);

            return;
        }

        $run->update([
            'status' => JobRun::STATUS_FAILED,
            'message' => $this->exceptionSummary($exception),
            'exception' => $exception,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($run->started_at, $finishedAt),
        ]);
    }

    public function recordScheduledStarting(ScheduledTaskStarting $event): void
    {
        $name = $this->resolveScheduledName($event);

        $run = JobRun::query()->create([
            'correlation_id' => 'scheduled:'.$name.':'.now()->format('Y-m-d-H-i-s-u'),
            'type' => JobRun::TYPE_SCHEDULED,
            'name' => $name,
            'status' => JobRun::STATUS_RUNNING,
            'trigger' => JobRun::TRIGGER_SCHEDULE,
            'started_at' => now(),
        ]);

        self::$scheduledRunIds[spl_object_id($event->task)] = $run->id;
    }

    public function recordScheduledFinished(ScheduledTaskFinished $event): void
    {
        $run = $this->resolveScheduledRun($event);

        if ($run === null) {
            return;
        }

        $finishedAt = now();

        $run->update([
            'status' => JobRun::STATUS_COMPLETED,
            'message' => 'Scheduled task completed.',
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($run->started_at, $finishedAt),
        ]);

        unset(self::$scheduledRunIds[spl_object_id($event->task)]);
    }

    public function recordScheduledFailed(ScheduledTaskFailed $event): void
    {
        $run = $this->resolveScheduledRun($event);

        $finishedAt = now();
        $exception = (string) $event->exception;
        $name = $this->resolveScheduledName($event);

        if ($run === null) {
            JobRun::query()->create([
                'correlation_id' => 'scheduled:'.$name.':'.now()->format('Y-m-d-H-i-s-u'),
                'type' => JobRun::TYPE_SCHEDULED,
                'name' => $name,
                'status' => JobRun::STATUS_FAILED,
                'trigger' => JobRun::TRIGGER_SCHEDULE,
                'message' => $this->exceptionSummary($exception),
                'exception' => $exception,
                'started_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => 0,
            ]);

            return;
        }

        $run->update([
            'status' => JobRun::STATUS_FAILED,
            'message' => $this->exceptionSummary($exception),
            'exception' => $exception,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($run->started_at, $finishedAt),
        ]);

        unset(self::$scheduledRunIds[spl_object_id($event->task)]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveQueueJobName(array $payload): string
    {
        $displayName = $payload['displayName'] ?? null;

        if (is_string($displayName) && $displayName !== '') {
            return class_basename($displayName);
        }

        return 'UnknownJob';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveQueueContext(array $payload): array
    {
        $command = $payload['data']['command'] ?? null;

        if (! is_string($command)) {
            return [];
        }

        try {
            $instance = unserialize($command, ['allowed_classes' => true]);
        } catch (\Throwable) {
            return [];
        }

        if ($instance instanceof FetchHikvisionAccessEventsJob && filled($instance->date)) {
            return ['date' => $instance->date];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveQueueTrigger(string $name, array $context): string
    {
        if ($name === 'FetchHikvisionAccessEventsJob') {
            return filled($context['date'] ?? null)
                ? JobRun::TRIGGER_MANUAL
                : JobRun::TRIGGER_SCHEDULE;
        }

        return JobRun::TRIGGER_SYSTEM;
    }

    private function findRunningQueueRun(?string $uuid, string $name): ?JobRun
    {
        if ($uuid !== null) {
            $run = JobRun::query()
                ->where('correlation_id', $uuid)
                ->where('type', JobRun::TYPE_QUEUE)
                ->where('status', JobRun::STATUS_RUNNING)
                ->latest('id')
                ->first();

            if ($run !== null) {
                return $run;
            }
        }

        return JobRun::query()
            ->where('type', JobRun::TYPE_QUEUE)
            ->where('name', $name)
            ->where('status', JobRun::STATUS_RUNNING)
            ->latest('id')
            ->first();
    }

    private function resolveScheduledName(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed $event): string
    {
        $description = trim($event->task->description ?? '');

        if ($description !== '') {
            return $description;
        }

        $command = $event->task->command ?? null;

        if (is_string($command) && $command !== '') {
            return $command;
        }

        return 'scheduled-task';
    }

    private function resolveScheduledRun(ScheduledTaskFinished|ScheduledTaskFailed $event): ?JobRun
    {
        $runId = self::$scheduledRunIds[spl_object_id($event->task)] ?? null;

        if ($runId === null) {
            return null;
        }

        return JobRun::query()->find($runId);
    }

    private function durationMs(CarbonInterface $startedAt, CarbonInterface $finishedAt): int
    {
        return max(0, (int) $startedAt->diffInMilliseconds($finishedAt));
    }

    private function exceptionSummary(string $exception): string
    {
        $line = strtok($exception, "\n");

        if (! is_string($line) || $line === '') {
            return 'Job failed.';
        }

        return mb_strlen($line) > 255 ? mb_substr($line, 0, 252).'...' : $line;
    }
}
