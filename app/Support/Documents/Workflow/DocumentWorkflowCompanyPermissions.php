<?php

namespace App\Support\Documents\Workflow;

use App\Models\User;
use Closure;
use Spatie\Permission\PermissionRegistrar;

final class DocumentWorkflowCompanyPermissions
{
    public function canReview(User $user, int $companyId): bool
    {
        return $this->withinCompany($user, $companyId, fn (): bool => $user->can('documents.requests.view')
            && $user->can('documents.requests.review'));
    }

    public function canApprove(User $user, int $companyId): bool
    {
        return $this->withinCompany($user, $companyId, fn (): bool => $user->can('documents.requests.view')
            && $user->can('documents.requests.approve'));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withinCompany(User $user, int $companyId, Closure $callback): mixed
    {
        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($companyId);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
