<?php

namespace App\Support\Companies;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

final class ActivateCompanySession
{
    public function handle(User $user, int $companyId, Request $request): void
    {
        $isMember = $user->companies()->whereKey($companyId)->exists()
            || ($user->company_id && (int) $user->company_id === $companyId);

        abort_unless($isMember, 403);

        $request->session()->put('current_company_id', $companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
    }
}
