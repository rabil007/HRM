<?php

namespace App\Support\Documents\Workflow;

use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Support\Facades\DB;

final class DocumentWorkflowAssigneeOptionsQuery
{
    public function __construct(
        private readonly DocumentWorkflowCompanyPermissions $workflowPermissions = new DocumentWorkflowCompanyPermissions,
    ) {}

    /**
     * @return list<array{id: int, name: string, email: string|null, can_review: bool, can_approve: bool}>
     */
    public function forCompany(int $companyId): array
    {
        $companyAccess = new ResolveCompanyAccess;

        $users = User::query()
            ->select('users.id', 'users.name', 'users.email', 'users.status', 'users.company_id')
            ->where('users.status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('users.company_id', $companyId)
                    ->orWhereExists(function ($inner) use ($companyId): void {
                        $inner->select(DB::raw(1))
                            ->from('company_user')
                            ->whereColumn('company_user.user_id', 'users.id')
                            ->where('company_user.company_id', $companyId)
                            ->where('company_user.status', 'active');
                    });
            })
            ->orderBy('users.name')
            ->get();

        $eligibleUsers = $users
            ->filter(fn (User $user): bool => $companyAccess->hasAccessibleMembership($user, $companyId))
            ->values();

        $capabilitiesByUserId = $this->workflowPermissions->capabilitiesByUserId($eligibleUsers, $companyId);

        return $eligibleUsers
            ->map(function (User $user) use ($capabilitiesByUserId): ?array {
                $capabilities = $capabilitiesByUserId[(int) $user->id] ?? [
                    'can_review' => false,
                    'can_approve' => false,
                ];

                if (! $capabilities['can_review'] && ! $capabilities['can_approve']) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'email' => $user->email,
                    'can_review' => $capabilities['can_review'],
                    'can_approve' => $capabilities['can_approve'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
