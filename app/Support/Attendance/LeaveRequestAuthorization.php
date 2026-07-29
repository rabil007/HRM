<?php

namespace App\Support\Attendance;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Row-level leave-request capabilities. Assigned-approver access grants view and
 * current-step approve/reject only — never edit, cancel, or delete.
 */
final class LeaveRequestAuthorization
{
    public function __construct(
        private LeaveRequestVisibility $visibility,
    ) {}

    public function canView(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        return $this->visibility->canAccess($leaveRequest, $user, $companyId);
    }

    public function canEdit(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if (! $this->passesCompanyAndMutationPermission($leaveRequest, $user, $companyId, 'attendance.leave-requests.update')) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        if (! $this->isOwnerOrViewAllAdmin($leaveRequest, $user, $companyId)) {
            return false;
        }

        // Presentation flag: hide edit once approval has started. Mutation actions
        // still re-check under lock and return a validation error with a clear message.
        return ! $this->approvalProcessHasStarted($leaveRequest, $companyId);
    }

    /**
     * Structural authority to attempt an edit (owner/admin). Business state is enforced in the action.
     */
    public function canAttemptEdit(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if (! $this->passesCompanyAndMutationPermission($leaveRequest, $user, $companyId, 'attendance.leave-requests.update')) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        return $this->isOwnerOrViewAllAdmin($leaveRequest, $user, $companyId);
    }

    public function canCancel(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if (! $this->passesCompanyAndMutationPermission($leaveRequest, $user, $companyId, 'attendance.leave-requests.update')) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        return $this->isOwnerOrViewAllAdmin($leaveRequest, $user, $companyId);
    }

    public function canDelete(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if (! $this->passesCompanyAndMutationPermission($leaveRequest, $user, $companyId, 'attendance.leave-requests.delete')) {
            return false;
        }

        if (! in_array($leaveRequest->status, ['pending', 'cancelled'], true)) {
            return false;
        }

        return $this->isOwnerOrViewAllAdmin($leaveRequest, $user, $companyId);
    }

    public function canApproveCurrentStep(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if ($user === null || (int) $leaveRequest->company_id !== $companyId) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        if (! $user->can('attendance.leave-requests.approve')) {
            return false;
        }

        if ($leaveRequest->relationLoaded('approvals')) {
            return $leaveRequest->approvals->contains(
                function (LeaveRequestApproval $approval) use ($user): bool {
                    $status = $approval->status instanceof LeaveRequestApprovalStatus
                        ? $approval->status
                        : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

                    return $status === LeaveRequestApprovalStatus::Pending
                        && (int) $approval->approver_user_id === (int) $user->id;
                },
            );
        }

        return $this->visibility->canApproveCurrentStep($leaveRequest, $user, $companyId);
    }

    public function assertCanView(LeaveRequest $leaveRequest, ?User $user, int $companyId): void
    {
        abort_unless($this->canView($leaveRequest, $user, $companyId), 404);
    }

    public function assertCanEdit(LeaveRequest $leaveRequest, ?User $user, int $companyId): void
    {
        $this->assertCanView($leaveRequest, $user, $companyId);
        abort_unless($this->canAttemptEdit($leaveRequest, $user, $companyId), 403);
    }

    public function assertCanCancel(LeaveRequest $leaveRequest, ?User $user, int $companyId): void
    {
        $this->assertCanView($leaveRequest, $user, $companyId);
        abort_unless($this->canCancel($leaveRequest, $user, $companyId), 403);
    }

    public function assertCanDelete(LeaveRequest $leaveRequest, ?User $user, int $companyId): void
    {
        $this->assertCanView($leaveRequest, $user, $companyId);
        abort_unless($this->canDelete($leaveRequest, $user, $companyId), 403);
    }

    /**
     * @return array{
     *     can_edit: bool,
     *     can_cancel: bool,
     *     can_delete: bool,
     *     can_approve_current_step: bool,
     * }
     */
    public function capabilities(LeaveRequest $leaveRequest, ?User $user, int $companyId): array
    {
        return [
            'can_edit' => $this->canEdit($leaveRequest, $user, $companyId),
            'can_cancel' => $this->canCancel($leaveRequest, $user, $companyId),
            'can_delete' => $this->canDelete($leaveRequest, $user, $companyId),
            'can_approve_current_step' => $this->canApproveCurrentStep($leaveRequest, $user, $companyId),
        ];
    }

    public function approvalProcessHasStarted(LeaveRequest $leaveRequest, int $companyId): bool
    {
        $approvals = $this->approvalsFor($leaveRequest, $companyId);

        foreach ($approvals as $approval) {
            $status = $approval->status instanceof LeaveRequestApprovalStatus
                ? $approval->status
                : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

            if ($status !== null && $status->isTerminal()) {
                return true;
            }
        }

        return false;
    }

    private function passesCompanyAndMutationPermission(
        LeaveRequest $leaveRequest,
        ?User $user,
        int $companyId,
        string $permission,
    ): bool {
        if ($user === null || (int) $leaveRequest->company_id !== $companyId) {
            return false;
        }

        return $user->can($permission);
    }

    private function isOwnerOrViewAllAdmin(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if ($user === null) {
            return false;
        }

        $employeeId = $this->visibility->linkedEmployeeId($user, $companyId);

        if ($employeeId !== null && (int) $leaveRequest->employee_id === $employeeId) {
            return true;
        }

        return $this->visibility->canViewAll($user);
    }

    /**
     * @return Collection<int, LeaveRequestApproval>
     */
    private function approvalsFor(LeaveRequest $leaveRequest, int $companyId): Collection
    {
        if ($leaveRequest->relationLoaded('approvals')) {
            return $leaveRequest->approvals;
        }

        return LeaveRequestApproval::query()
            ->where('company_id', $companyId)
            ->where('leave_request_id', $leaveRequest->id)
            ->get();
    }
}
