<?php

use App\Http\Middleware\ApplyRememberedSessionLifetimeEarly;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsurePrivilegedTwoFactor;
use App\Http\Middleware\ExtendRememberedSessionLifetime;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogFailedFileUploads;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentCompany;
use App\Support\Uploads\FailedUploadLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'whatsapp/webhook',
            'webhooks/whatsapp',
            'webhooks/hikvision',
            'integrations/hikvision/webhook/*',
        ]);

        $middleware->web(prepend: [
            ApplyRememberedSessionLifetimeEarly::class,
        ]);

        $trustedProxies = env('TRUSTED_PROXIES');

        if (is_string($trustedProxies) && $trustedProxies !== '' && $trustedProxies !== '*') {
            $middleware->trustProxies(
                at: array_values(array_filter(array_map(trim(...), explode(',', $trustedProxies)))),
            );
        }

        $middleware->alias([
            'platform' => EnsurePlatformAccess::class,
            'privileged.2fa' => EnsurePrivilegedTwoFactor::class,
        ]);

        $middleware->web(append: [
            EnsureActiveUser::class,
            ExtendRememberedSessionLifetime::class,
            HandleAppearance::class,
            SetCurrentCompany::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            LogFailedFileUploads::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            if (app()->runningInConsole()) {
                return;
            }

            $request = request();

            if (! $request instanceof Request || ! FailedUploadLogger::requestHasFiles($request)) {
                return;
            }

            if ($exception instanceof RuntimeException) {
                return;
            }

            FailedUploadLogger::logException($request, $exception);
        });
        $renderForbiddenInertiaPage = function (Request $request) {
            if ($request->header(Header::INERTIA)) {
                return true;
            }

            $accept = (string) $request->header('Accept', '');

            return $accept === '' || str_contains($accept, 'text/html');
        };

        $exceptions->render(function (AuthorizationException $e, $request) use ($renderForbiddenInertiaPage) {
            if (! $renderForbiddenInertiaPage($request)) {
                return null;
            }

            return Inertia::render('errors/403')->toResponse($request)->setStatusCode(403);
        });

        $exceptions->render(function (HttpExceptionInterface $e, $request) use ($renderForbiddenInertiaPage) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }

            if (! $renderForbiddenInertiaPage($request)) {
                return null;
            }

            return Inertia::render('errors/403')->toResponse($request)->setStatusCode(403);
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            return app(SecurityHeaders::class)->apply($request, $response);
        });
    })->create();
