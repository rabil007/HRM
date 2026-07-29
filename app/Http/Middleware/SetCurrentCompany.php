<?php

namespace App\Http\Middleware;

use App\Models\Company;
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

        $accessibleCompanyIds = $this->accessibleCompanyIds($user);
        $currentCompanyId = $request->session()->get('current_company_id');

        if ($currentCompanyId !== null && in_array((int) $currentCompanyId, $accessibleCompanyIds, true)) {
            $currentCompanyId = (int) $currentCompanyId;
            $request->attributes->set('current_company_id', $currentCompanyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($currentCompanyId);

            return $next($request);
        }

        $fallbackCompanyId = $this->resolveFallbackCompanyId($user, $accessibleCompanyIds);

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

    /**
     * Active companies with an active company_user membership, plus the legacy
     * home-company path when no pivot row exists for that user/company.
     *
     * @return list<int>
     */
    private function accessibleCompanyIds(mixed $user): array
    {
        $activeMembershipIds = $user->companies()
            ->where('companies.status', 'active')
            ->wherePivot('status', 'active')
            ->pluck('companies.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($user->company_id) {
            $homeCompanyId = (int) $user->company_id;
            $hasAnyPivotForHome = $user->companies()->whereKey($homeCompanyId)->exists();

            if (! $hasAnyPivotForHome) {
                $homeIsActive = Company::query()
                    ->whereKey($homeCompanyId)
                    ->where('status', 'active')
                    ->exists();

                if ($homeIsActive && ! in_array($homeCompanyId, $activeMembershipIds, true)) {
                    $activeMembershipIds[] = $homeCompanyId;
                }
            }
        }

        return array_values(array_unique($activeMembershipIds));
    }

    /**
     * @param  list<int>  $accessibleCompanyIds
     */
    private function resolveFallbackCompanyId(mixed $user, array $accessibleCompanyIds): ?int
    {
        if ($user->company_id && in_array((int) $user->company_id, $accessibleCompanyIds, true)) {
            return (int) $user->company_id;
        }

        return $accessibleCompanyIds[0] ?? null;
    }
}
