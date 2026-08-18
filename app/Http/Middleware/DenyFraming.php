<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyFraming
{
    /**
     * Explicit framing denial for public announcement routes.
     *
     * CSP `frame-ancestors` and a global `X-Frame-Options: DENY` are applied by
     * SecurityHeaders. This middleware must not set Content-Security-Policy
     * itself, because that would replace the full policy with frame-ancestors only.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
