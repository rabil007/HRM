<?php

namespace App\Http\Middleware;

use App\Support\Auth\PrivilegedTwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedTwoFactor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        PrivilegedTwoFactorPolicy::assertSatisfied($user);

        return $next($request);
    }
}
