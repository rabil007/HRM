<?php

namespace App\Support\Documents;

use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

final class DocumentTemplateLayoutValidationFailureLogger
{
    public const EVENT = 'document_template_layout_preflight_failed';

    public const REFERENCE_PREFIX = 'LAY-';

    public static function newReference(): string
    {
        return self::REFERENCE_PREFIX.Str::ulid();
    }

    /**
     * @param  array{
     *     company_id: int,
     *     template_id: int,
     *     template_version_id: int,
     *     template_type?: string|null,
     *     validation_mode?: string|null,
     *     user_id?: int|null
     * }  $context
     */
    public function record(Throwable $exception, string $reference, array $context): void
    {
        $availability = ResolvesBrowsershotBinaries::availabilitySnapshot();
        $previous = $exception->getPrevious();
        $processFailure = self::processFailure($exception);

        $payload = [
            'event' => self::EVENT,
            'reference_id' => $reference,
            'company_id' => $context['company_id'],
            'template_id' => $context['template_id'],
            'version_id' => $context['template_version_id'],
            'template_type' => $context['template_type'] ?? null,
            'validation_mode' => $context['validation_mode'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'exception' => $exception::class,
            'exception_message' => self::sanitizeMessage($exception->getMessage()),
            'cause' => $previous !== null ? $previous::class : ($processFailure !== null ? $processFailure::class : null),
            'cause_message' => $previous !== null ? self::sanitizeMessage($previous->getMessage()) : null,
            'node_resolved' => $availability['node_resolved'],
            'npm_resolved' => $availability['npm_resolved'],
            'chrome_resolved' => $availability['chrome_resolved'],
            'node_modules_available' => $availability['node_modules_available'],
        ];

        if ($processFailure !== null) {
            $process = $processFailure->getProcess();
            $stderr = trim((string) $process->getErrorOutput());
            $stdout = trim((string) $process->getOutput());

            $payload['cause'] = ProcessFailedException::class;
            $payload['process_exit_code'] = $process->getExitCode();
            $payload['process_error_output'] = self::sanitizeMessage(
                $stderr !== '' ? $stderr : $stdout,
                1000,
            );
            unset($payload['cause_message']);
        }

        Log::error(self::EVENT, $payload);
    }

    public static function sanitizeMessage(string $message, int $maxLength = 240): string
    {
        $sanitized = preg_replace('/(?:[A-Za-z]:)?(?:\\\\|\/)[^\s\'"]+/', '[path]', $message) ?? $message;
        $sanitized = preg_replace('/784[-\d]{6,}/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(?:sk|pk|token|secret|password|bearer)[-_]?[A-Za-z0-9+\/=]{8,}\b/i', '[redacted]', $sanitized) ?? $sanitized;

        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength).'…';
        }

        return $sanitized;
    }

    private static function processFailure(Throwable $exception): ?ProcessFailedException
    {
        $current = $exception;

        while ($current !== null) {
            if ($current instanceof ProcessFailedException) {
                return $current;
            }

            $current = $current->getPrevious();
        }

        return null;
    }
}
