<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class InertiaHttpErrorRenderer
{
    /**
     * @var list<int>
     */
    public const SUPPORTED_STATUSES = [400, 401, 403, 404, 419, 429, 500, 503];

    public static function wantsHtmlErrorDocument(Request $request): bool
    {
        if ($request->expectsJson() && ! $request->header(Header::INERTIA)) {
            return false;
        }

        if ($request->header(Header::INERTIA)) {
            return true;
        }

        $accept = (string) $request->header('Accept', '');

        return $accept === '' || str_contains($accept, 'text/html');
    }

    /**
     * @param  array<string, mixed>  $props
     */
    public static function render(Request $request, int $status, array $props = []): Response
    {
        return Inertia::render('errors/'.$status, $props)
            ->toResponse($request)
            ->setStatusCode($status);
    }

    public static function resolveStatus(Throwable $exception): ?int
    {
        if ($exception instanceof AuthorizationException) {
            return 403;
        }

        if ($exception instanceof TokenMismatchException) {
            return 419;
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        return null;
    }

    public static function shouldRenderInertiaPage(
        Request $request,
        Throwable $exception,
        int $status,
    ): bool {
        if (! in_array($status, self::SUPPORTED_STATUSES, true)) {
            return false;
        }

        if ($exception instanceof ValidationException) {
            return false;
        }

        if ($exception instanceof AuthenticationException) {
            return false;
        }

        if ($status === 500 && app()->hasDebugModeEnabled()) {
            return false;
        }

        if (! self::wantsHtmlErrorDocument($request)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function propsFor(Request $request, Throwable $exception, int $status): array
    {
        $props = [];

        if ($status === 429 && $exception instanceof HttpExceptionInterface) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

            if (is_numeric($retryAfter)) {
                $props['retry_after_seconds'] = (int) $retryAfter;
            }
        }

        return $props;
    }

    public static function tryRender(Request $request, Throwable $exception): ?Response
    {
        if ($exception instanceof ValidationException) {
            return null;
        }

        $status = self::resolveStatus($exception);

        if ($status === null) {
            if (app()->hasDebugModeEnabled() || ! self::wantsHtmlErrorDocument($request)) {
                return null;
            }

            $status = 500;
        }

        if (! self::shouldRenderInertiaPage($request, $exception, $status)) {
            return null;
        }

        return self::render(
            $request,
            $status,
            self::propsFor($request, $exception, $status),
        );
    }
}
