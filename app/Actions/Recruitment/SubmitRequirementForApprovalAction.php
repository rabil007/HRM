<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RequirementSubmissionReadiness;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use App\Support\Settings\CompanyCurrency;
use Illuminate\Support\Facades\DB;

final class SubmitRequirementForApprovalAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        User $actor,
        bool $dispatchNotifications = true,
    ): RecruitmentRequirement {
        RequirementWorkflowAuthorization::assertCanSubmit($actor, $requirement);

        $result = DB::transaction(function () use ($requirement, $actor): array {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanSubmit($actor, $locked);

            $locked->loadMissing(['lines.position']);
            RequirementSubmissionReadiness::assertReady($locked);

            foreach ($locked->lines->filter(fn ($line) => $line->status !== RequirementLineStatus::Cancelled) as $line) {
                if (empty($line->salary_currency_code)) {
                    $line->update([
                        'salary_currency_code' => CompanyCurrency::codeForCompany($locked->company_id),
                    ]);
                }
            }

            $fromStatus = $locked->status;
            $now = now();

            $locked->update([
                'status' => RequirementStatus::PendingApproval,
                'submitted_at' => $now,
                'submitted_by' => $actor->id,
                'returned_at' => null,
                'returned_by' => null,
                'return_reason' => null,
                'updated_by' => $actor->id,
            ]);

            $transition = RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::PendingApproval,
                (int) $actor->id,
            );

            activity('recruitment')
                ->causedBy($actor->id)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'from_status' => $fromStatus->value,
                    'to_status' => RequirementStatus::PendingApproval->value,
                ])
                ->log("Requirement {$locked->requirement_number} submitted for approval.");

            $fresh = $locked->fresh([
                'assignedRecruiter',
                'creator',
                'submitter',
                'client',
                'project',
                'lines.position',
                'notificationRecipients.user',
                'company',
            ]) ?? $locked;

            return [$fresh, $transition];
        });

        /** @var RecruitmentRequirement $requirement */
        [$requirement, $transition] = $result;

        if ($dispatchNotifications) {
            SendRequirementLifecycleEmails::submittedForApproval($requirement, $transition);
        }

        return $requirement;
    }
}
