<?php

namespace App\Support\Uploads;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class FailedUploadLogger
{
    public const LOG_MESSAGE = 'File upload failed.';

    public static function log(Request $request, string $reason, array $context = []): void
    {
        Log::error(self::LOG_MESSAGE, array_merge(
            self::requestContext($request),
            [
                'reason' => $reason,
            ],
            $context,
        ));
    }

    public static function logException(Request $request, Throwable $exception, array $context = []): void
    {
        self::log($request, $exception->getMessage(), array_merge([
            'exception' => $exception::class,
        ], $context));
    }

    public static function logStorageFailure(
        UploadedFile $file,
        string $operation,
        string $path,
        string $reason,
        ?Throwable $exception = null,
        array $context = [],
    ): void {
        $request = request();

        if (! $request instanceof Request) {
            Log::error(self::LOG_MESSAGE, array_merge([
                'reason' => $reason,
                'operation' => $operation,
                'path' => $path,
                'file' => self::describeFile($file),
                'exception' => $exception !== null ? $exception::class : null,
            ], $context));

            return;
        }

        self::log($request, $reason, array_merge([
            'failure_stage' => 'storage',
            'operation' => $operation,
            'path' => $path,
            'file' => self::describeFile($file),
            'exception' => $exception !== null ? $exception::class : null,
        ], self::routeUploadModuleContext($request), $context));
    }

    /**
     * @return array<string, mixed>
     */
    public static function routeUploadModuleContext(Request $request): array
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName)) {
            return [];
        }

        return match (true) {
            str_contains($routeName, 'employees.training.') => [
                'upload_module' => 'employee_training_certificate',
            ],
            str_contains($routeName, 'employees.documents.') => [
                'upload_module' => 'employee_document',
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function requestContext(Request $request): array
    {
        return [
            'user_id' => $request->user()?->id,
            'company_id' => $request->attributes->get('current_company_id'),
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'files' => self::describeFiles($request->allFiles()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function responseFailureContext(Request $request, Response $response): array
    {
        $context = [
            'status' => $response->getStatusCode(),
        ];

        if ($request->session()->has('errors')) {
            $context['validation_errors'] = $request->session()->get('errors')?->getMessages();
        }

        $flashError = $request->session()->get('error');

        if (is_string($flashError) && $flashError !== '') {
            $context['flash_error'] = $flashError;
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $files
     * @return list<array<string, mixed>>
     */
    public static function describeFiles(array $files): array
    {
        $descriptions = [];

        foreach (self::flattenFiles($files) as $file) {
            $descriptions[] = self::describeFile($file);
        }

        return $descriptions;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describeFile(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'client_mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
            'is_valid' => $file->isValid(),
            'upload_error' => $file->getError(),
            'upload_error_message' => $file->getErrorMessage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $files
     * @return list<UploadedFile>
     */
    public static function flattenFiles(array $files): array
    {
        $flattened = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $flattened[] = $file;

                continue;
            }

            if (is_array($file)) {
                $flattened = array_merge($flattened, self::flattenFiles($file));
            }
        }

        return $flattened;
    }

    public static function requestHasFiles(Request $request): bool
    {
        return self::flattenFiles($request->allFiles()) !== [];
    }
}
