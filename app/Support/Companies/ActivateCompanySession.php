<?php

namespace App\Support\Companies;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

final class ActivateCompanySession
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess,
    ) {}

    public function handle(User $user, int $companyId, Request $request): void
    {
        abort_unless($this->companyAccess->canAccess($user, $companyId), 403);

        $request->session()->put('current_company_id', $companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
    }
}
