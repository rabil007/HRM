<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Support\Header;

class PrivilegedTwoFactorRequiredException extends Exception
{
    public const MESSAGE = 'Two-factor authentication is required for this action.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE, 403);
    }

    public function report(): false
    {
        return false;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header(Header::INERTIA)) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()
            ->route('security.edit')
            ->with('error', self::MESSAGE);
    }
}
