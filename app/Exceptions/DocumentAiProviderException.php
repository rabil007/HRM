<?php

namespace App\Exceptions;

use App\Enums\DocumentAiErrorCode;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use InvalidArgumentException;
use Throwable;

class DocumentAiProviderException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly DocumentAiErrorCode $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct(DocumentAiErrorCode::userMessage(), 0, $previous);
    }

    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        return new self(self::classify($exception), $exception);
    }

    public static function classify(Throwable $exception): DocumentAiErrorCode
    {
        $current = $exception;

        while ($current !== null) {
            if ($current instanceof self) {
                return $current->errorCode;
            }

            if ($current instanceof InvalidArgumentException) {
                return DocumentAiErrorCode::InvalidOutput;
            }

            $message = strtolower($current->getMessage());

            if (
                str_contains($message, '429')
                || str_contains($message, 'rate limit')
                || str_contains($message, 'too many requests')
            ) {
                return DocumentAiErrorCode::ProviderRateLimited;
            }

            if (
                str_contains($message, 'timeout')
                || str_contains($message, 'timed out')
                || str_contains($message, 'time-out')
            ) {
                return DocumentAiErrorCode::ProviderTimeout;
            }

            if (
                str_contains($message, '503')
                || str_contains($message, '502')
                || str_contains($message, '500')
                || str_contains($message, 'unavailable')
                || str_contains($message, 'connection')
                || str_contains($message, 'could not resolve')
                || str_contains($message, 'network')
            ) {
                return DocumentAiErrorCode::ProviderUnavailable;
            }

            $current = $current->getPrevious();
        }

        return DocumentAiErrorCode::ExtractionFailed;
    }
}
