<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $memberCompanyIds = $user->companies()->pluck('companies.id')->all();

        if (empty($memberCompanyIds) && $user->company_id) {
            $memberCompanyIds = [(int) $user->company_id];
        }

        $currentCompanyId = $request->session()->get('current_company_id');

        if ($currentCompanyId && in_array((int) $currentCompanyId, $memberCompanyIds, true)) {
            $currentCompanyId = (int) $currentCompanyId;
            $request->attributes->set('current_company_id', $currentCompanyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($currentCompanyId);

            return $next($request);
        }

        $fallbackCompanyId = $user->company_id ?: ($memberCompanyIds[0] ?? null);

        if ($fallbackCompanyId) {
            $fallbackCompanyId = (int) $fallbackCompanyId;
            $request->session()->put('current_company_id', $fallbackCompanyId);
            $request->attributes->set('current_company_id', $fallbackCompanyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($fallbackCompanyId);
        }

        return $next($request);
    }
}
