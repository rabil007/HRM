<?php

namespace App\Support\Queue;

final class ScheduledJobRunRecording
{
    public const ALWAYS = 'always';

    public const FAILURES_ONLY = 'failures_only';

    /**
     * Whether a successful scheduled run should be omitted from job_runs.
     *
     * Failures are always recorded. Unknown commands use {@see self::ALWAYS}.
     */
    public static function recordsFailuresOnly(?string $command): bool
    {
        $name = self::artisanCommandName($command);

        if ($name === null) {
            return false;
        }

        $policies = config('queue.scheduled_job_run_recording', []);

        if (! is_array($policies)) {
            return false;
        }

        return ($policies[$name] ?? self::ALWAYS) === self::FAILURES_ONLY;
    }

    public static function artisanCommandName(?string $command): ?string
    {
        if (! is_string($command) || trim($command) === '') {
            return null;
        }

        if (preg_match("/\\bartisan['\"]?\\s+([a-z0-9:_-]+)/i", $command, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
