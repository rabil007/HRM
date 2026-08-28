<?php

namespace App\Support\Documents\Workflow;

use App\Models\Employee;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;

/**
 * Whether a user is actionable as a document workflow assignee for a stage action.
 */
final class DocumentWorkflowAssigneeEligibility
{
    public function __construct(
        private readonly ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
        private readonly DocumentWorkflowCompanyPermissions $workflowPermissions = new DocumentWorkflowCompanyPermissions,
    ) {}

    public function isActionable(User $user, int $companyId, string $stageAction, ?int $requesterUserId = null): bool
    {
        if ($requesterUserId !== null && (int) $user->id === $requesterUserId) {
            return false;
        }

        if ($user->status !== 'active') {
            return false;
        }

        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId($companyId, [(int) $user->id]);

        if (! ($membershipByUserId[(int) $user->id] ?? false)) {
            return false;
        }

        return match ($stageAction) {
            'review' => $this->workflowPermissions->canReview($user, $companyId),
            'approve' => $this->workflowPermissions->canApprove($user, $companyId),
            default => false,
        };
    }

    /**
     * @param  iterable<int, User>  $users
     * @return array<int, bool>
     */
    public function actionableByUserId(iterable $users, int $companyId, string $stageAction, ?int $requesterUserId = null): array
    {
        $users = collect($users)->keyBy('id');
        $capabilities = $this->workflowPermissions->capabilitiesByUserId($users, $companyId);
        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId(
            $companyId,
            $users->keys()->map(fn ($id): int => (int) $id)->values()->all(),
        );
        $result = [];

        foreach ($users as $user) {
            $userId = (int) $user->id;

            if ($requesterUserId !== null && $userId === $requesterUserId) {
                $result[$userId] = false;

                continue;
            }

            if ($user->status !== 'active') {
                $result[$userId] = false;

                continue;
            }

            if (! ($membershipByUserId[$userId] ?? false)) {
                $result[$userId] = false;

                continue;
            }

            $capabilitiesForUser = $capabilities[$userId] ?? [
                'can_review' => false,
                'can_approve' => false,
            ];

            $result[$userId] = match ($stageAction) {
                'review' => $capabilitiesForUser['can_review'],
                'approve' => $capabilitiesForUser['can_approve'],
                default => false,
            };
        }

        return $result;
    }

    public function isManagerActionable(Employee $manager, int $companyId, string $stageAction, ?int $requesterUserId = null): bool
    {
        if ($manager->status !== 'active' || (int) $manager->company_id !== $companyId) {
            return false;
        }

        $manager->loadMissing('user:id,name,email,status');

        if ($manager->user === null) {
            return false;
        }

        return $this->isActionable($manager->user, $companyId, $stageAction, $requesterUserId);
    }
}
