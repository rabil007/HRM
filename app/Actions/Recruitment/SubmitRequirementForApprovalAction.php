<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use App\Support\Settings\CompanyCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitRequirementForApprovalAction
{
    public function execute(RecruitmentRequirement $requirement, User $actor): RecruitmentRequirement
    {
        if (! $actor->can('recruitment.requirements.submit')) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to submit requirements for approval.',
            ]);
        }

        $result = DB::transaction(function () use ($requirement, $actor): array {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [RequirementStatus::Draft, RequirementStatus::Returned], true)) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be submitted from {$locked->status->label()} status.",
                ]);
            }

            if ($locked->assigned_to === null) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'An assigned recruiter is required before submitting for approval.',
                ]);
            }

            RecruiterOptionsQuery::assertEligibleApprover(
                (int) $locked->assigned_to,
                (int) $locked->company_id,
                required: true,
            );

            if ((int) $locked->created_by === (int) $locked->assigned_to) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
                ]);
            }

            $activeLines = $locked->lines->filter(fn ($line) => $line->status !== RequirementLineStatus::Cancelled);

            if ($activeLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'At least one active position line is required before submitting for approval.',
                ]);
            }

            foreach ($activeLines as $line) {
                if ($line->salary_min === null || $line->salary_max === null) {
                    throw ValidationException::withMessages([
                        'salary' => 'Both minimum and maximum salary are required for every active position line before submitting for approval.',
                    ]);
                }

                if (! is_numeric($line->salary_min) || ! is_numeric($line->salary_max)) {
                    throw ValidationException::withMessages([
                        'salary' => 'Salary values must be valid numbers.',
                    ]);
                }

                $min = (float) $line->salary_min;
                $max = (float) $line->salary_max;

                if ($min < 0 || $max < 0) {
                    throw ValidationException::withMessages([
                        'salary' => 'Salary values cannot be negative.',
                    ]);
                }

                if (preg_match('/^\d+(\.\d{1,2})?$/', (string) $line->salary_min) !== 1 || preg_match('/^\d+(\.\d{1,2})?$/', (string) $line->salary_max) !== 1) {
                    throw ValidationException::withMessages([
                        'salary' => 'Salary values may not have more than 2 decimal places.',
                    ]);
                }

                if ($max < $min) {
                    throw ValidationException::withMessages([
                        'salary' => 'Maximum salary must be greater than or equal to minimum salary for every position line.',
                    ]);
                }

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

        SendRequirementLifecycleEmails::submittedForApproval($requirement, $transition);

        return $requirement;
    }
}
