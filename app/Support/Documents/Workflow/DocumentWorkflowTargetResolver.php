<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowTargetType;
use App\Models\DocumentWorkflowPresetTarget;
use App\Models\Employee;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Departments\ResolveDepartmentManagementChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class DocumentWorkflowTargetResolver
{
    public function __construct(
        private readonly DocumentWorkflowAssigneeEligibility $eligibility = new DocumentWorkflowAssigneeEligibility,
        private readonly ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
    ) {}

    /**
     * @param  list<DocumentWorkflowPresetTarget>  $targets
     * @return list<int>
     */
    public function resolveUserIdsForStage(
        array $targets,
        Employee $subjectEmployee,
        int $companyId,
        string $stageAction,
        int $requesterUserId,
    ): array {
        $resolvedUserIds = [];

        foreach ($targets as $target) {
            $userIds = match ($target->target_type) {
                DocumentWorkflowTargetType::SpecificUser => $this->resolveSpecificUser($target, $companyId, $stageAction, $requesterUserId),
                DocumentWorkflowTargetType::DepartmentManager => $this->resolveDepartmentManager($subjectEmployee, $companyId, $stageAction, $requesterUserId),
                DocumentWorkflowTargetType::ParentManager => $this->resolveParentManager($subjectEmployee, $companyId, $stageAction, $requesterUserId),
                DocumentWorkflowTargetType::CompanyRole => $this->resolveCompanyRole($target, $companyId, $stageAction, $requesterUserId),
            };

            foreach ($userIds as $userId) {
                $resolvedUserIds[] = $userId;
            }
        }

        return array_values(array_unique($resolvedUserIds));
    }

    /**
     * @return list<int>
     */
    private function resolveSpecificUser(
        DocumentWorkflowPresetTarget $target,
        int $companyId,
        string $stageAction,
        int $requesterUserId,
    ): array {
        if ($target->target_user_id === null) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['A specific-user target is missing its user assignment.'],
            ]);
        }

        $user = User::query()
            ->whereKey($target->target_user_id)
            ->first(['id', 'name', 'status', 'company_id']);

        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId(
            $companyId,
            [(int) $target->target_user_id],
        );

        if (
            $user === null
            || ! ($membershipByUserId[(int) $target->target_user_id] ?? false)
            || ! $this->eligibility->isActionable($user, $companyId, $stageAction, $requesterUserId)
        ) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['One or more specific-user targets could not be resolved to an eligible assignee.'],
            ]);
        }

        return [(int) $user->id];
    }

    /**
     * @return list<int>
     */
    private function resolveDepartmentManager(
        Employee $subjectEmployee,
        int $companyId,
        string $stageAction,
        int $requesterUserId,
    ): array {
        $userId = $this->firstActionableManagerUserId($subjectEmployee, $companyId, $stageAction, $requesterUserId, skipCount: 0);

        if ($userId === null) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['No actionable department manager could be resolved for this employee.'],
            ]);
        }

        return [$userId];
    }

    /**
     * @return list<int>
     */
    private function resolveParentManager(
        Employee $subjectEmployee,
        int $companyId,
        string $stageAction,
        int $requesterUserId,
    ): array {
        $userId = $this->firstActionableManagerUserId($subjectEmployee, $companyId, $stageAction, $requesterUserId, skipCount: 1);

        if ($userId === null) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['No actionable parent manager could be resolved for this employee.'],
            ]);
        }

        return [$userId];
    }

    private function firstActionableManagerUserId(
        Employee $subjectEmployee,
        int $companyId,
        string $stageAction,
        int $requesterUserId,
        int $skipCount,
    ): ?int {
        $chain = ResolveDepartmentManagementChain::forEmployee($subjectEmployee);
        $actionableIndex = 0;

        foreach ($chain as $entry) {
            /** @var Employee $manager */
            $manager = $entry['manager'];

            if (! $this->eligibility->isManagerActionable($manager, $companyId, $stageAction, $requesterUserId)) {
                continue;
            }

            if ($actionableIndex < $skipCount) {
                $actionableIndex++;

                continue;
            }

            $manager->loadMissing('user:id');

            return $manager->user !== null ? (int) $manager->user->id : null;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function resolveCompanyRole(
        DocumentWorkflowPresetTarget $target,
        int $companyId,
        string $stageAction,
        int $requesterUserId,
    ): array {
        if ($target->target_role_id === null) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['A company-role target is missing its role assignment.'],
            ]);
        }

        $role = Role::query()
            ->whereKey($target->target_role_id)
            ->where('company_id', $companyId)
            ->first(['id', 'name']);

        if ($role === null) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['One or more company-role targets reference an invalid role for this company.'],
            ]);
        }

        $modelHasRoles = config('permission.table_names.model_has_roles');
        $teamKey = config('permission.column_names.team_foreign_key', 'company_id');

        $userIds = DB::table($modelHasRoles)
            ->where('role_id', $role->id)
            ->where($teamKey, $companyId)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => [sprintf('No eligible users were found for the "%s" role.', $role->name)],
            ]);
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('status', 'active')
            ->get(['id', 'name', 'status', 'company_id']);

        $actionable = $this->eligibility->actionableByUserId($users, $companyId, $stageAction, $requesterUserId);
        $eligibleUserIds = [];

        foreach ($users as $user) {
            if ($actionable[(int) $user->id] ?? false) {
                $eligibleUserIds[] = (int) $user->id;
            }
        }

        if ($eligibleUserIds === []) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => [sprintf('No eligible users with the required permissions were found for the "%s" role.', $role->name)],
            ]);
        }

        return $eligibleUserIds;
    }
}
