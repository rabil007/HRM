<?php

namespace App\Http\Middleware;

use App\Support\Uploads\FailedUploadLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogFailedFileUploads
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! FailedUploadLogger::requestHasFiles($request)) {
            return $next($request);
        }

        $response = $next($request);

        if (! $this->shouldLogFailedUpload($request, $response)) {
            return $response;
        }

        FailedUploadLogger::log(
            $request,
            $this->resolveFailureReason($request, $response),
            array_merge(
                FailedUploadLogger::responseFailureContext($request, $response),
                FailedUploadLogger::routeUploadModuleContext($request),
            ),
        );

        return $response;
    }

    private function shouldLogFailedUpload(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        if ($request->session()->has('errors')) {
            return true;
        }

        $flashError = $request->session()->get('error');

        return is_string($flashError) && $flashError !== '';
    }

    private function resolveFailureReason(Request $request, Response $response): string
    {
        if ($request->session()->has('errors')) {
            return 'Upload request failed validation.';
        }

        $flashError = $request->session()->get('error');

        if (is_string($flashError) && $flashError !== '') {
            return $flashError;
        }

        return 'Upload request failed with HTTP '.$response->getStatusCode().'.';
    }
}
