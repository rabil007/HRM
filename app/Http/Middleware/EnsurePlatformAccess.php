<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        $user = $request->user();

        $allowed = match ($ability) {
            'manage' => PlatformAuthorization::canManage($user),
            'database' => PlatformAuthorization::canViewDatabase($user),
            default => PlatformAuthorization::canView($user),
        };

        if (! $allowed) {
            abort(403);
        }

        return $next($request);
    }
}
