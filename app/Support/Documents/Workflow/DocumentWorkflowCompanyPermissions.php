<?php

namespace App\Support\Documents\Workflow;

use App\Models\User;
use Closure;
use Spatie\Permission\PermissionRegistrar;

final class DocumentWorkflowCompanyPermissions
{
    /**
     * @param  iterable<int, User>  $users
     * @return array<int, array{can_review: bool, can_approve: bool}>
     */
    public function capabilitiesByUserId(iterable $users, int $companyId): array
    {
        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $users = collect($users);

        if ($users->isEmpty()) {
            return [];
        }

        $userModels = User::query()
            ->whereIn('id', $users->pluck('id'))
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->keyBy('id');

        try {
            $registrar->setPermissionsTeamId($companyId);

            $capabilities = [];

            foreach ($users as $user) {
                $permissionUser = $userModels->get($user->id) ?? $user;

                $capabilities[(int) $user->id] = [
                    'can_review' => $permissionUser->can('documents.requests.view')
                        && $permissionUser->can('documents.requests.review'),
                    'can_approve' => $permissionUser->can('documents.requests.view')
                        && $permissionUser->can('documents.requests.approve'),
                ];
            }

            return $capabilities;
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);

            foreach ($userModels as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }

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
