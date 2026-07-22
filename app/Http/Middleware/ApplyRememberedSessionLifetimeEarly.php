<?php

namespace App\Http\Middleware;

use App\Support\Auth\RememberSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApplyRememberedSessionLifetimeEarly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->cookies->has(Auth::guard('web')->getRecallerName())) {
            RememberSession::extendLifetime();
        }

        return $next($request);
    }
}
