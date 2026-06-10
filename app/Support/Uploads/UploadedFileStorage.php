<?php

namespace App\Support\Uploads;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

final class UploadedFileStorage
{
    /**
     * @param  array<string, mixed>|string  $options
     */
    public static function store(UploadedFile $file, string $path, array|string $options = []): string
    {
        $storageOptions = self::storageOptions($options);

        return self::persist($file, 'store', $path, $options, fn () => $file->store($path, $storageOptions));
    }

    /**
     * @param  array<string, mixed>|string  $options
     */
    public static function storeAs(
        UploadedFile $file,
        string $path,
        string $name,
        array|string $options = [],
    ): string {
        $storageOptions = self::storageOptions($options);

        return self::persist(
            $file,
            'storeAs',
            $path.'/'.$name,
            $options,
            fn () => $file->storeAs($path, $name, $storageOptions),
        );
    }

    /**
     * @param  array<string, mixed>|string  $options
     */
    public static function storePublicly(UploadedFile $file, string $path, array|string $options = []): string
    {
        $storageOptions = self::storageOptions($options);

        return self::persist(
            $file,
            'storePublicly',
            $path,
            $options,
            fn () => $file->storePublicly($path, $storageOptions),
        );
    }

    /**
     * @param  array<string, mixed>|string  $options
     */
    private static function persist(
        UploadedFile $file,
        string $operation,
        string $path,
        array|string $options,
        callable $callback,
    ): string {
        $logContext = self::logContextFromOptions($options);

        if (! $file->isValid()) {
            FailedUploadLogger::logStorageFailure(
                $file,
                $operation,
                $path,
                $file->getErrorMessage() !== ''
                    ? $file->getErrorMessage()
                    : 'Uploaded file is not valid.',
                null,
                $logContext,
            );

            throw new RuntimeException('Uploaded file is not valid.');
        }

        try {
            $storedPath = $callback();

            if (! is_string($storedPath) || $storedPath === '') {
                FailedUploadLogger::logStorageFailure(
                    $file,
                    $operation,
                    $path,
                    "{$operation} did not return a stored path.",
                    null,
                    $logContext,
                );

                throw new RuntimeException('Failed to store uploaded file.');
            }

            return $storedPath;
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                FailedUploadLogger::logStorageFailure(
                    $file,
                    $operation,
                    $path,
                    $exception->getMessage(),
                    $exception,
                    $logContext,
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>|string  $options
     * @return array<string, mixed>
     */
    private static function logContextFromOptions(array|string $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $context = $options['log_context'] ?? [];

        return is_array($context) ? $context : [];
    }

    /**
     * @param  array<string, mixed>|string  $options
     * @return array<string, mixed>|string
     */
    private static function storageOptions(array|string $options): array|string
    {
        if (! is_array($options)) {
            return $options;
        }

        unset($options['log_context']);

        return $options;
    }
}
