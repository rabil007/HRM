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
        return self::persist($file, 'store', $path, $options, fn () => $file->store($path, $options));
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
        return self::persist(
            $file,
            'storeAs',
            $path.'/'.$name,
            $options,
            fn () => $file->storeAs($path, $name, $options),
        );
    }

    /**
     * @param  array<string, mixed>|string  $options
     */
    public static function storePublicly(UploadedFile $file, string $path, array|string $options = []): string
    {
        return self::persist(
            $file,
            'storePublicly',
            $path,
            $options,
            fn () => $file->storePublicly($path, $options),
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
        if (! $file->isValid()) {
            FailedUploadLogger::logStorageFailure(
                $file,
                $operation,
                $path,
                $file->getErrorMessage() !== ''
                    ? $file->getErrorMessage()
                    : 'Uploaded file is not valid.',
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
                );
            }

            throw $exception;
        }
    }
}
