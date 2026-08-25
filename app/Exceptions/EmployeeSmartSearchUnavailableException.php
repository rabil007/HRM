<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeSmartSearchUnavailableException extends Exception implements ShouldntReport
{
    public const MESSAGE = 'Employee smart search is temporarily unavailable.';

    public const REJECTED_CREDENTIALS = 'The stored API key was rejected by the selected provider.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message, 503);
    }

    public static function providerFailed(): self
    {
        return new self;
    }

    public static function missingCredentials(): self
    {
        return new self;
    }

    public static function rejectedCredentials(): self
    {
        return new self(self::REJECTED_CREDENTIALS);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE], 503);
    }
}
