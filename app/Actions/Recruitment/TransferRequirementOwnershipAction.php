<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\CompanyUserOptionsQuery;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementOwnershipTransferEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransferRequirementOwnershipAction
{
    /**
     * @param  array{created_by: int, assigned_to: int|null, reason: string}  $data
     */
    public function execute(
        RecruitmentRequirement $requirement,
        User $actor,
        array $data,
    ): RecruitmentRequirement {
        /** @var list<array{user_id: int, role: 'requester'|'recruiter'}> $notifyRecipients */
        $notifyRecipients = [];
        /** @var RecruitmentRequirementStatusTransition|null $ownershipTransition */
        $ownershipTransition = null;

        $result = DB::transaction(function () use (
            $requirement,
            $actor,
            $data,
            &$notifyRecipients,
            &$ownershipTransition,
        ): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanTransferOwnership($actor, $locked);

            $companyId = (int) $locked->company_id;
            $currentStatus = $locked->status;
            $previousRequesterId = $locked->created_by !== null ? (int) $locked->created_by : null;
            $previousRecruiterId = $locked->assigned_to !== null ? (int) $locked->assigned_to : null;

            $newRequesterId = (int) $data['created_by'];
            $newRecruiterId = array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null
                ? (int) $data['assigned_to']
                : null;
            $reason = trim((string) $data['reason']);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required when transferring ownership.',
                ]);
            }

            if (! CompanyUserOptionsQuery::isActiveCompanyMember($newRequesterId, $companyId)) {
                throw ValidationException::withMessages([
                    'created_by' => 'The selected requester is inactive or does not belong to this company.',
                ]);
            }

            $requiresAssignedRecruiter = in_array($currentStatus, [
                RequirementStatus::PendingApproval,
                RequirementStatus::Open,
                RequirementStatus::OnHold,
            ], true);

            if ($requiresAssignedRecruiter && $newRecruiterId === null) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'An assigned recruiter is required for this requirement status.',
                ]);
            }

            if ($newRecruiterId !== null) {
                RecruiterOptionsQuery::assertEligibleApprover($newRecruiterId, $companyId, required: true);
            }

            if ($newRecruiterId !== null && $newRequesterId === $newRecruiterId) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
                ]);
            }

            $requesterChanged = $previousRequesterId !== $newRequesterId;
            $recruiterChanged = $previousRecruiterId !== $newRecruiterId;

            if (! $requesterChanged && ! $recruiterChanged) {
                throw ValidationException::withMessages([
                    'status' => 'Select a different requester or assigned recruiter to transfer ownership.',
                ]);
            }

            $previousRequester = $previousRequesterId !== null
                ? User::query()->find($previousRequesterId)
                : null;
            $newRequester = User::query()->findOrFail($newRequesterId);
            $previousRecruiter = $previousRecruiterId !== null
                ? User::query()->find($previousRecruiterId)
                : null;
            $newRecruiter = $newRecruiterId !== null
                ? User::query()->find($newRecruiterId)
                : null;

            $locked->update([
                'created_by' => $newRequesterId,
                'assigned_to' => $newRecruiterId,
                'updated_by' => $actor->id,
            ]);

            $ownershipTransition = RecordRequirementStatusTransition::handle(
                $locked,
                $currentStatus,
                $currentStatus,
                (int) $actor->id,
                'Ownership transferred',
                [
                    'previous_requester_user_id' => $previousRequesterId,
                    'new_requester_user_id' => $newRequesterId,
                    'previous_recruiter_user_id' => $previousRecruiterId,
                    'new_recruiter_user_id' => $newRecruiterId,
                    'reason' => $reason,
                ],
            );

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $companyId,
                    'requirement_number' => $locked->requirement_number,
                    'reason' => $reason,
                    'previous_requester_user_id' => $previousRequesterId,
                    'previous_requester_name' => $previousRequester?->name,
                    'new_requester_user_id' => $newRequesterId,
                    'new_requester_name' => $newRequester->name,
                    'previous_recruiter_user_id' => $previousRecruiterId,
                    'previous_recruiter_name' => $previousRecruiter?->name,
                    'new_recruiter_user_id' => $newRecruiterId,
                    'new_recruiter_name' => $newRecruiter?->name,
                ])
                ->log("Ownership transferred for requirement {$locked->requirement_number}.");

            if ($requesterChanged) {
                $notifyRecipients[] = [
                    'user_id' => $newRequesterId,
                    'role' => 'requester',
                ];
            }

            if ($recruiterChanged && $newRecruiterId !== null) {
                $notifyRecipients[] = [
                    'user_id' => $newRecruiterId,
                    'role' => 'recruiter',
                ];
            }

            return $locked->load([
                'assignedRecruiter',
                'creator',
                'notificationRecipients',
                'company',
            ]);
        });

        if (
            $ownershipTransition instanceof RecruitmentRequirementStatusTransition
            && $notifyRecipients !== []
        ) {
            SendRequirementOwnershipTransferEmails::notify(
                $result,
                $ownershipTransition,
                $notifyRecipients,
            );
        }

        return $result;
    }
}
