<?php

namespace App\Support\Documents;

use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        Log::error(self::EVENT, [
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
            'cause' => $previous !== null ? $previous::class : null,
            'cause_message' => $previous !== null ? self::sanitizeMessage($previous->getMessage()) : null,
            'node_resolved' => $availability['node_resolved'],
            'npm_resolved' => $availability['npm_resolved'],
            'chrome_resolved' => $availability['chrome_resolved'],
            'node_modules_available' => $availability['node_modules_available'],
        ]);
    }

    public static function sanitizeMessage(string $message): string
    {
        $sanitized = preg_replace('/(?:[A-Za-z]:)?(?:\\\\|\/)[^\s\'"]+/', '[path]', $message) ?? $message;
        $sanitized = preg_replace('/784[-\d]{6,}/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '[redacted]', $sanitized) ?? $sanitized;

        if (strlen($sanitized) > 240) {
            $sanitized = substr($sanitized, 0, 240).'…';
        }

        return $sanitized;
    }
}
