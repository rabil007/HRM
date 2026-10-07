<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementDeadlineExtensionInitiator;
use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\User;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementDeadlineExtensionEmails;
use App\Support\Recruitment\ValidateRequirementDeadlineExtension;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExtendDeadlineAction
{
    /**
     * @return array{requirement: RecruitmentRequirement, mode: 'direct'|'request'}
     */
    public function execute(
        RecruitmentRequirement $requirement,
        User $actor,
        string $newDate,
        ?string $reason,
    ): array {
        $trimmedReason = $reason !== null ? trim($reason) : '';

        if (RequirementWorkflowAuthorization::canDirectlyExtendDeadline($actor, $requirement)) {
            $locked = $this->applyDirect($requirement, $actor, $newDate, $trimmedReason);

            return ['requirement' => $locked, 'mode' => 'direct'];
        }

        if (RequirementWorkflowAuthorization::canRequestDeadlineExtension($actor, $requirement)) {
            $locked = $this->requestExtension($requirement, $actor, $newDate, $trimmedReason);

            return ['requirement' => $locked, 'mode' => 'request'];
        }

        throw ValidationException::withMessages([
            'status' => 'You are not allowed to extend or request an extension for this deadline.',
        ]);
    }

    private function applyDirect(
        RecruitmentRequirement $requirement,
        User $actor,
        string $newDate,
        string $reason,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $actor, $newDate, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanDirectlyExtendDeadline($actor, $locked);
            ValidateRequirementDeadlineExtension::assertNewDeadline($locked, $newDate);

            $oldDate = $locked->required_by_date?->format('Y-m-d');
            if ($oldDate === null) {
                $oldDate = $newDate;
            }

            $this->cancelPendingRequests($locked, $actor, 'The requester updated the official deadline.');

            $locked->update([
                'required_by_date' => $newDate,
                'updated_by' => $actor->id,
            ]);

            $extension = RecruitmentRequirementDeadlineExtension::query()->create([
                'company_id' => $locked->company_id,
                'recruitment_requirement_id' => $locked->id,
                'requested_by' => $actor->id,
                'initiator' => RequirementDeadlineExtensionInitiator::Requester,
                'old_deadline' => $oldDate,
                'requested_deadline' => $newDate,
                'reason' => $reason !== '' ? $reason : null,
                'status' => RequirementDeadlineExtensionStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => null,
            ]);

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'deadline_extension_id' => $extension->id,
                    'initiator' => RequirementDeadlineExtensionInitiator::Requester->value,
                    'old_required_by_date' => $oldDate,
                    'new_required_by_date' => $newDate,
                    'reason' => $reason !== '' ? $reason : null,
                    'status' => RequirementDeadlineExtensionStatus::Approved->value,
                ])
                ->log("Deadline extended by requester from {$oldDate} to {$newDate}.");

            $fresh = $locked->fresh(['assignedRecruiter', 'notificationRecipients', 'creator']) ?? $locked;
            SendRequirementDeadlineExtensionEmails::direct($fresh, $extension);

            return $fresh;
        });
    }

    private function requestExtension(
        RecruitmentRequirement $requirement,
        User $actor,
        string $newDate,
        string $reason,
    ): RecruitmentRequirement {
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when requesting a deadline extension.',
            ]);
        }

        return DB::transaction(function () use ($requirement, $actor, $newDate, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanRequestDeadlineExtension($actor, $locked);
            ValidateRequirementDeadlineExtension::assertNewDeadline($locked, $newDate);

            $alreadyPending = RecruitmentRequirementDeadlineExtension::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'status' => 'A deadline extension request is already waiting for requester approval.',
                ]);
            }

            $oldDate = $locked->required_by_date?->format('Y-m-d');
            if ($oldDate === null) {
                throw ValidationException::withMessages([
                    'new_date' => 'This requirement does not have a deadline to extend.',
                ]);
            }

            $extension = RecruitmentRequirementDeadlineExtension::query()->create([
                'company_id' => $locked->company_id,
                'recruitment_requirement_id' => $locked->id,
                'requested_by' => $actor->id,
                'initiator' => RequirementDeadlineExtensionInitiator::Recruiter,
                'old_deadline' => $oldDate,
                'requested_deadline' => $newDate,
                'reason' => $reason,
                'status' => RequirementDeadlineExtensionStatus::Pending,
            ]);

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'deadline_extension_id' => $extension->id,
                    'initiator' => RequirementDeadlineExtensionInitiator::Recruiter->value,
                    'old_required_by_date' => $oldDate,
                    'new_required_by_date' => $newDate,
                    'reason' => $reason,
                    'status' => RequirementDeadlineExtensionStatus::Pending->value,
                ])
                ->log("Deadline extension requested from {$oldDate} to {$newDate}.");

            $fresh = $locked->fresh(['creator', 'assignedRecruiter', 'notificationRecipients']) ?? $locked;
            SendRequirementDeadlineExtensionEmails::requested($fresh, $extension);

            return $fresh;
        });
    }

    private function cancelPendingRequests(
        RecruitmentRequirement $requirement,
        User $actor,
        string $decisionNote,
    ): void {
        $pending = RecruitmentRequirementDeadlineExtension::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->where('company_id', $requirement->company_id)
            ->pending()
            ->lockForUpdate()
            ->get();

        foreach ($pending as $extension) {
            $extension->update([
                'status' => RequirementDeadlineExtensionStatus::Cancelled,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
            ]);
        }
    }
}
