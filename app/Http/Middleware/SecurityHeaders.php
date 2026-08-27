<?php

namespace App\Http\Middleware;

use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $this->applyContentSecurityPolicy($response);
        $this->applyFramingProtection($response);
        $this->applyStaticHeaders($response);
        $this->applyHsts($request, $response);
        $this->applySensitivePlatformCache($request, $response);

        return $response;
    }

    private function applyContentSecurityPolicy(Response $response): void
    {
        $header = filter_var(config('security.headers.csp.report_only'), FILTER_VALIDATE_BOOLEAN)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, ContentSecurityPolicy::headerValue());
        $response->headers->remove(
            $header === 'Content-Security-Policy'
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy',
        );
    }

    private function applyFramingProtection(Response $response): void
    {
        $frameOptions = config('security.headers.x_frame_options', 'SAMEORIGIN');

        if ($frameOptions === false || $frameOptions === null || $frameOptions === '' || in_array(strtolower((string) $frameOptions), ['false', 'off', 'none'], true)) {
            $response->headers->remove('X-Frame-Options');

            return;
        }

        $response->headers->set('X-Frame-Options', (string) $frameOptions);
    }

    private function applyStaticHeaders(Response $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Referrer-Policy',
            (string) config('security.headers.referrer_policy', 'strict-origin-when-cross-origin'),
        );
        $response->headers->set(
            'Permissions-Policy',
            (string) config('security.headers.permissions_policy'),
        );
    }

    private function applyHsts(Request $request, Response $response): void
    {
        if ($response->headers->has('Strict-Transport-Security')) {
            return;
        }

        if (! $request->secure() || ! $this->hstsEnabled()) {
            return;
        }

        $maxAge = (int) config('security.headers.hsts.max_age', 31536000);
        $value = 'max-age='.$maxAge;

        if (filter_var(config('security.headers.hsts.include_subdomains'), FILTER_VALIDATE_BOOLEAN)) {
            $value .= '; includeSubDomains';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    private function hstsEnabled(): bool
    {
        $configured = config('security.headers.hsts.enabled');

        if ($configured === null || $configured === '') {
            return app()->environment('production');
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    private function applySensitivePlatformCache(Request $request, Response $response): void
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName)) {
            return;
        }

        $sensitive = $routeName === 'log'
            || str_starts_with($routeName, 'log.')
            || str_starts_with($routeName, 'jobs.')
            || str_starts_with($routeName, 'mysql.');

        if (! $sensitive) {
            return;
        }

        $existing = (string) $response->headers->get('Cache-Control', '');

        if (str_contains($existing, 'no-store')) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
    }
}
