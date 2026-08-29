<?php

namespace App\Support\Documents\Signing;

use App\Models\Employee;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Departments\ResolveDepartmentManagementChain;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves ordered, actionable, unique management-chain signers for advanced flows.
 *
 * Deduplicates by recipient User id (not only Employee id).
 */
final class DocumentSigningManagementChainResolver
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
    ) {}

    /**
     * @return list<array{manager: Employee, user: User, management_chain_position: int}>
     */
    public function resolveActionableUniqueManagers(Employee $subjectEmployee, int $companyId): array
    {
        if ((int) $subjectEmployee->company_id !== $companyId) {
            return [];
        }

        $chain = ResolveDepartmentManagementChain::forEmployee($subjectEmployee);
        $resolved = [];
        $seenUserIds = [];

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

            $userId = (int) $user->id;

            if (isset($seenUserIds[$userId])) {
                continue;
            }

            $seenUserIds[$userId] = true;
            $resolved[] = [
                'manager' => $manager,
                'user' => $user,
                'management_chain_position' => count($resolved) + 1,
            ];
        }

        return $resolved;
    }

    /**
     * @return array{manager: Employee, user: User, management_chain_position: int}
     */
    public function resolveAtPosition(
        Employee $subjectEmployee,
        int $companyId,
        int $position,
    ): array {
        if ($position < 1) {
            throw ValidationException::withMessages([
                'action' => 'Invalid management chain position.',
            ]);
        }

        $managers = $this->resolveActionableUniqueManagers($subjectEmployee, $companyId);

        if (count($managers) < $position) {
            if ($position === 1) {
                throw ValidationException::withMessages([
                    'action' => 'No eligible department manager is available to sign this document.',
                ]);
            }

            throw ValidationException::withMessages([
                'action' => sprintf(
                    'This signing preset requires %d eligible management signers, but only %d are available in the employee\'s management hierarchy.',
                    $position,
                    count($managers),
                ),
            ]);
        }

        return $managers[$position - 1];
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

        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId(
            $companyId,
            [(int) $user->id],
        );

        if (! ($membershipByUserId[(int) $user->id] ?? false)) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        return $user->can('documents.recipient-requests.respond');
    }
}
