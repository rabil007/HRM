<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FillRequirementAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        ?string $reason = null,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $userId, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be marked as filled from {$locked->status->label()} status.",
                ]);
            }

            $pendingDeadlineExtension = RecruitmentRequirementDeadlineExtension::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->exists();

            $pendingHeadcountRevision = RecruitmentRequirementHeadcountRevision::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->exists();

            if ($pendingDeadlineExtension && $pendingHeadcountRevision) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement has pending deadline-extension and headcount-revision requests. Approve or reject them before marking the requirement as filled.',
                ]);
            }

            if ($pendingDeadlineExtension) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement has a pending deadline extension. Approve or reject the request before marking the requirement as filled.',
                ]);
            }

            if ($pendingHeadcountRevision) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement has a pending headcount revision. Approve or reject the revision before marking the requirement as filled.',
                ]);
            }

            // Calculate fulfillment per position line
            $lines = $locked->lines()
                ->with('position')
                ->where('status', '!=', RequirementLineStatus::Cancelled)
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot mark requirement as filled because there are no active position lines. Please cancel the requirement instead.',
                ]);
            }

            $fulfillmentSnapshot = [];
            $totalRemaining = 0;
            $totalOverfill = 0;
            $lineStatusMap = [];

            foreach ($lines as $line) {
                $joinedCount = $line->candidates()
                    ->where('stage', CandidateStage::Joined->value)
                    ->count();
                $required = (int) $line->required_headcount;
                $remaining = max(0, $required - $joinedCount);
                $overfill = max(0, $joinedCount - $required);
                $isLineFulfilled = $joinedCount >= $required;

                $fulfillmentSnapshot[] = [
                    'line_id' => (int) $line->id,
                    'position_id' => (int) $line->position_id,
                    'position_title' => $line->position?->title ?? "Position #{$line->position_id}",
                    'required_headcount' => $required,
                    'joined_count' => $joinedCount,
                    'remaining_headcount' => $remaining,
                    'overfill' => $overfill,
                    'line_status' => $isLineFulfilled ? RequirementLineStatus::Filled->value : RequirementLineStatus::Closed->value,
                ];

                $lineStatusMap[$line->id] = $isLineFulfilled ? RequirementLineStatus::Filled : RequirementLineStatus::Closed;
                $totalRemaining += $remaining;
                $totalOverfill += $overfill;
            }

            $actor = User::query()->findOrFail($userId);
            $trimmedReason = trim((string) $reason);

            if ($totalRemaining > 0) {
                // Unfilled positions remain: prevent ordinary Filled action unless authorized with management override and mandatory reason
                if (! RequirementWorkflowAuthorization::canOverrideFill($actor)) {
                    throw ValidationException::withMessages([
                        'status' => "This requirement has {$totalRemaining} unfilled vacancy(ies). Marking it as filled requires management override authorization.",
                    ]);
                }

                if ($trimmedReason === '') {
                    throw ValidationException::withMessages([
                        'reason' => 'A mandatory reason is required when marking a partially fulfilled requirement as filled.',
                    ]);
                }
            }

            $fromStatus = $locked->status;

            $locked->update([
                'status' => RequirementStatus::Completed,
                'completed_at' => now(),
                'updated_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $line->update([
                    'status' => $lineStatusMap[$line->id],
                ]);
            }

            RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::Completed,
                $userId,
                $trimmedReason !== '' ? $trimmedReason : null,
                [
                    'unfilled_vacancies' => $totalRemaining,
                    'overfill_count' => $totalOverfill,
                    'partially_fulfilled' => $totalRemaining > 0,
                    'fulfillment_snapshot' => $fulfillmentSnapshot,
                ],
            );

            $activity = activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'unfilled_vacancies' => $totalRemaining,
                    'overfill_count' => $totalOverfill,
                    'partially_fulfilled' => $totalRemaining > 0,
                    'fulfillment_snapshot' => $fulfillmentSnapshot,
                    'reason' => $trimmedReason !== '' ? $trimmedReason : null,
                ]);

            if ($totalRemaining > 0) {
                $activity->log("Requirement {$locked->requirement_number} marked as Filled/Completed with management override ({$totalRemaining} unfilled vacancies). Reason: {$trimmedReason}");
            } else {
                $activity->log("Requirement {$locked->requirement_number} marked as Filled/Completed.");
            }

            return $locked;
        });
    }
}
