<?php

namespace App\Support\CompanyDocuments;

use App\Models\Company;
use App\Models\User;
use Closure;
use Spatie\Permission\PermissionRegistrar;

class CompanyDocumentAccess
{
    public const Abilities = [
        'view' => 'company_documents.view',
        'upload' => 'company_documents.upload',
        'update' => 'company_documents.update',
        'download' => 'company_documents.download',
        'delete' => 'company_documents.delete',
    ];

    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    public function authorize(?User $user, Company $company, string $ability): void
    {
        abort_unless($user instanceof User && $this->isActiveMember($user, $company), 404);
        abort_unless($this->withinCompany($user, $company, fn () => $user->can($ability)), 403);
    }

    public function allows(?User $user, Company $company, string $ability): bool
    {
        if (! $user instanceof User || ! $this->isActiveMember($user, $company)) {
            return false;
        }

        return $this->withinCompany($user, $company, fn () => $user->can($ability));
    }

    /** @return array{view: bool, upload: bool, update: bool, download: bool, delete: bool} */
    public function permissions(?User $user, Company $company): array
    {
        if (! $user instanceof User || ! $this->isActiveMember($user, $company)) {
            return array_fill_keys(array_keys(self::Abilities), false);
        }

        return $this->withinCompany($user, $company, function () use ($user): array {
            return collect(self::Abilities)
                ->mapWithKeys(fn (string $ability, string $key) => [$key => $user->can($ability)])
                ->all();
        });
    }

    public function isActiveMember(User $user, Company $company): bool
    {
        return $user->companies()
            ->whereKey($company->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function withinCompany(User $user, Company $company, Closure $callback): mixed
    {
        $originalTeamId = $this->permissionRegistrar->getPermissionsTeamId();

        try {
            $this->permissionRegistrar->setPermissionsTeamId($company->id);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $callback();
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($originalTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
