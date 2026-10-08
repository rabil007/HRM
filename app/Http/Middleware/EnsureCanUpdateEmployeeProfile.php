<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Support\Employees\AuthorizeEmployeeProfileUpdate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanUpdateEmployeeProfile
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->route('employee');

        if (! $employee instanceof Employee) {
            abort(404);
        }

        $user = $request->user();

        if (! AuthorizeEmployeeProfileUpdate::allows($user, $employee)) {
            abort(403);
        }

        return $next($request);
    }
}
