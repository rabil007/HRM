<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnnouncementAiAssistUnavailableException extends Exception
{
    public static function providerFailed(): self
    {
        return new self('Announcement AI assistance is temporarily unavailable.');
    }

    public static function missingCredentials(): self
    {
        return new self('Announcement AI assistance is not configured.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 503);
    }
}
