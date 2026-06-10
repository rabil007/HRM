<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogFailedFileUploads;
use App\Http\Middleware\SetCurrentCompany;
use App\Support\Uploads\FailedUploadLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            SetCurrentCompany::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            LogFailedFileUploads::class,
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
        $exceptions->render(function (AuthorizationException $e, $request) {
            return Inertia::render('errors/403')->toResponse($request)->setStatusCode(403);
        });

        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }

            return Inertia::render('errors/403')->toResponse($request)->setStatusCode(403);
        });
    })->create();
