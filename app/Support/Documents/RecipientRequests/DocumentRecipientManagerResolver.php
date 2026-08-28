<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\Employee;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Departments\ResolveDepartmentManagementChain;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class DocumentRecipientManagerResolver
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
    ) {}

    /**
     * Resolve the first actionable department manager for signing.
     *
     * @return array{manager: Employee, user: User}
     *
     * @throws ValidationException
     */
    public function resolveForEmployee(Employee $subjectEmployee, int $companyId): array
    {
        if ((int) $subjectEmployee->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'action' => 'No eligible department manager is available to sign this document.',
            ]);
        }

        $chain = ResolveDepartmentManagementChain::forEmployee($subjectEmployee);

        foreach ($chain as $entry) {
            /** @var Employee $manager */
            $manager = $entry['manager'];

            if (! $this->isManagerEmployeeEligible($manager, $companyId)) {
                continue;
            }

            $manager->loadMissing('user:id,name,email,status');
            $user = $manager->user;

            if (! $user instanceof User) {
                continue;
            }

            if (! $this->isManagerUserEligible($user, $companyId)) {
                continue;
            }

            return [
                'manager' => $manager,
                'user' => $user,
            ];
        }

        throw ValidationException::withMessages([
            'action' => 'No eligible department manager is available to sign this document.',
        ]);
    }

    public function tryResolveForEmployee(Employee $subjectEmployee, int $companyId): ?array
    {
        try {
            return $this->resolveForEmployee($subjectEmployee, $companyId);
        } catch (ValidationException) {
            return null;
        }
    }

    private function isManagerEmployeeEligible(Employee $manager, int $companyId): bool
    {
        return (int) $manager->company_id === $companyId
            && $manager->status === 'active';
    }

    private function isManagerUserEligible(User $user, int $companyId): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        if (! $this->companyAccess->hasAccessibleMembership($user, $companyId)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        return $user->can('documents.recipient-requests.respond');
    }
}
