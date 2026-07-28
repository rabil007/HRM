<?php

namespace App\Support\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

final class ActivateCompanySession
{
    public function handle(User $user, int $companyId, Request $request): void
    {
        $company = Company::query()
            ->whereKey($companyId)
            ->where('status', 'active')
            ->first();

        abort_unless($company !== null, 403);

        $hasActiveMembership = $user->companies()
            ->whereKey($companyId)
            ->wherePivot('status', 'active')
            ->exists();

        $legacyHomeCompany = $user->company_id
            && (int) $user->company_id === $companyId
            && ! $user->companies()->whereKey($companyId)->exists();

        abort_unless($hasActiveMembership || $legacyHomeCompany, 403);

        $request->session()->put('current_company_id', $companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
    }
}
