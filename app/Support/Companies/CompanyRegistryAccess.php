<?php

namespace App\Support\Companies;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Companies registry CRUD is an active-company operation.
 *
 * Membership (switcher) decides which tenants a user may enter.
 * `companies.*` in the current Spatie team applies only to that tenant.
 */
final class CompanyRegistryAccess
{
    public static function activeCompanyId(Request $request): ?int
    {
        $id = $request->attributes->get('current_company_id');

        if ($id === null || (int) $id < 1) {
            return null;
        }

        return (int) $id;
    }

    public static function assertRouteCompanyIsActive(Request $request, Company $company): void
    {
        $activeId = self::activeCompanyId($request);

        abort_unless($activeId !== null && (int) $company->id === $activeId, 404);
    }

    /**
     * @return Builder<Company>
     */
    public static function queryForActiveCompany(Request $request): Builder
    {
        return Company::query()->whereKey(self::activeCompanyId($request) ?? 0);
    }
}
