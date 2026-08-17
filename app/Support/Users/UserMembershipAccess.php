<?php

namespace App\Support\Users;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * User membership mutations are an active-company operation.
 *
 * Trusted tenant = request.attributes.current_company_id.
 * Client/route company_id is never authorization.
 */
final class UserMembershipAccess
{
    public static function activeCompanyId(Request $request): ?int
    {
        $id = $request->attributes->get('current_company_id');

        if ($id === null || (int) $id < 1) {
            return null;
        }

        return (int) $id;
    }

    public static function assertActiveCompany(Request $request): int
    {
        $activeId = self::activeCompanyId($request);

        abort_unless($activeId !== null, 404);

        return $activeId;
    }

    public static function assertRouteCompanyIsActive(Request $request, Company $company): void
    {
        abort_unless((int) $company->id === self::assertActiveCompany($request), 404);
    }

    public static function assertMembershipInCompany(User $user, int $companyId): void
    {
        abort_unless($user->companies()->whereKey($companyId)->exists(), 404);
    }

    public static function syncRole(User $user, int $companyId, ?int $roleId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        if ($roleId === null) {
            $user->syncRoles([]);

            return;
        }

        $role = SpatieRole::query()
            ->whereKey($roleId)
            ->where('company_id', $companyId)
            ->first();

        abort_unless($role !== null, 404);

        $user->syncRoles([$role]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(Request $request, User $target, int $companyId, string $description, array $properties = []): void
    {
        activity()
            ->causedBy($request->user())
            ->performedOn($target)
            ->withProperties(array_merge([
                'company_id' => $companyId,
                'target_user_id' => $target->id,
            ], $properties))
            ->tap(function ($activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log($description);
    }
}
