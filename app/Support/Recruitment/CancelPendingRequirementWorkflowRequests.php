<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\RecruitmentRequirementHeadcountRevision;

/**
 * Atomically cancel pending deadline-extension and headcount-revision requests
 * when a requirement is cancelled. Caller must already hold a requirement row lock.
 */
final class CancelPendingRequirementWorkflowRequests
{
    public const CANCELLATION_NOTE = 'Cancelled because the requirement was cancelled.';

    public static function forRequirementCancellation(
        RecruitmentRequirement $requirement,
        int $actorId,
    ): void {
        self::cancelPendingDeadlineExtensions($requirement, $actorId);
        self::cancelPendingHeadcountRevisions($requirement, $actorId);
    }

    private static function cancelPendingDeadlineExtensions(
        RecruitmentRequirement $requirement,
        int $actorId,
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
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_note' => self::CANCELLATION_NOTE,
            ]);

            $currentDeadline = $requirement->required_by_date?->format('Y-m-d');

            activity('recruitment')
                ->causedBy($actorId)
                ->performedOn($requirement)
                ->withProperties([
                    'company_id' => $requirement->company_id,
                    'requirement_number' => $requirement->requirement_number,
                    'deadline_extension_id' => $extension->id,
                    'old_required_by_date' => $extension->old_deadline?->format('Y-m-d'),
                    'requested_deadline' => $extension->requested_deadline?->format('Y-m-d'),
                    'current_deadline' => $currentDeadline,
                    'reason' => $extension->reason,
                    'decision_note' => self::CANCELLATION_NOTE,
                    'status' => RequirementDeadlineExtensionStatus::Cancelled->value,
                ])
                ->log('Deadline extension cancelled because the requirement was cancelled. Official deadline remains unchanged.');
        }
    }

    private static function cancelPendingHeadcountRevisions(
        RecruitmentRequirement $requirement,
        int $actorId,
    ): void {
        $pending = RecruitmentRequirementHeadcountRevision::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->where('company_id', $requirement->company_id)
            ->pending()
            ->lockForUpdate()
            ->get();

        foreach ($pending as $revision) {
            $revision->load('lines');

            $revision->update([
                'status' => RequirementHeadcountRevisionStatus::Cancelled,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_note' => self::CANCELLATION_NOTE,
            ]);

            activity('recruitment')
                ->causedBy($actorId)
                ->performedOn($requirement)
                ->withProperties([
                    'company_id' => $requirement->company_id,
                    'requirement_number' => $requirement->requirement_number,
                    'headcount_revision_id' => $revision->id,
                    'changes' => $revision->lines->map(fn ($line): array => [
                        'position' => $line->position_title,
                        'old_headcount' => (int) $line->old_headcount,
                        'requested_headcount' => (int) $line->requested_headcount,
                    ])->all(),
                    'reason' => $revision->reason,
                    'decision_note' => self::CANCELLATION_NOTE,
                    'status' => RequirementHeadcountRevisionStatus::Cancelled->value,
                ])
                ->log('Headcount revision cancelled. Official headcount remains unchanged.');
        }
    }
}
