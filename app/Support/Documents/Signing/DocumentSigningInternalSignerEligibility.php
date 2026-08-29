<?php

namespace App\Support\Documents\Signing;

use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Spatie\Permission\PermissionRegistrar;

final class DocumentSigningInternalSignerEligibility
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
    ) {}

    public function isActionable(User $user, int $companyId): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        $membership = $this->companyAccess->accessibleMembershipByUserId($companyId, [(int) $user->id]);

        if (! ($membership[(int) $user->id] ?? false)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        return $user->can('documents.recipient-requests.respond');
    }
}
