<?php

namespace App\Enums;

enum DocumentAiErrorCode: string
{
    case ProviderTimeout = 'provider_timeout';
    case ProviderRateLimited = 'provider_rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidOutput = 'invalid_output';
    case UnsupportedFile = 'unsupported_file';
    case TemporaryFileMissing = 'temporary_file_missing';
    case Cancelled = 'cancelled';
    case CompanyAiDisabled = 'company_ai_disabled';
    case ExtractionFailed = 'extraction_failed';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::ProviderTimeout,
            self::ProviderRateLimited,
            self::ProviderUnavailable => true,
            default => false,
        };
    }

    public static function userMessage(): string
    {
        return 'AI extraction failed. You can retry or continue manually.';
    }
}
