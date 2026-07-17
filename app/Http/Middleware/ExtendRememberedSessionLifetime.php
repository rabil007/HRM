<?php

namespace App\Http\Middleware;

use App\Support\Auth\RememberSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExtendRememberedSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        RememberSession::applyLifetime();

        $response = $next($request);

        RememberSession::applyLifetime();

        return $response;
    }
}
