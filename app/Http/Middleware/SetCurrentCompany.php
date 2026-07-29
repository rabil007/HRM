<?php

namespace App\Http\Middleware;

use App\Support\Companies\ResolveCompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentCompany
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $accessibleCompanyIds = $this->companyAccess->accessibleCompanyIds($user);
        $currentCompanyId = $request->session()->get('current_company_id');

        if ($currentCompanyId !== null && in_array((int) $currentCompanyId, $accessibleCompanyIds, true)) {
            $currentCompanyId = (int) $currentCompanyId;
            $request->attributes->set('current_company_id', $currentCompanyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($currentCompanyId);

            return $next($request);
        }

        $fallbackCompanyId = $this->companyAccess->resolveFallbackCompanyId($user, $accessibleCompanyIds);

        if ($fallbackCompanyId !== null) {
            $request->session()->put('current_company_id', $fallbackCompanyId);
            $request->attributes->set('current_company_id', $fallbackCompanyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($fallbackCompanyId);

            return $next($request);
        }

        $request->session()->forget('current_company_id');
        $request->attributes->remove('current_company_id');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $next($request);
    }
}
