<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\User;
use Carbon\Carbon;

final class CandidatePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toIndexRow(RecruitmentCandidate $candidate, User $user, string $timezone): array
    {
        $canOwn = CandidateWorkflowAuthorization::hasOwnershipOrManage($user, $candidate->requirement);
        $parentsValid = CandidateWorkflowAuthorization::hasValidParentsForActions($candidate);
        $canUpdate = $user->can('recruitment.candidates.update') && $canOwn;
        $canMove = $user->can('recruitment.candidates.move') && $canOwn && $parentsValid;
        $canManage = $user->can('recruitment.candidates.manage');
        $canDownload = $user->can('recruitment.candidates.view')
            && $user->can('recruitment.candidates.cv.download')
            && $candidate->hasCv();

        return [
            'id' => (int) $candidate->id,
            'name' => (string) $candidate->name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'stage' => $candidate->stage->value,
            'stage_label' => $candidate->stage->label(),
            'stage_badge' => $candidate->stage->badgeVariant(),
            'interview_outcome' => $candidate->interview_outcome?->value,
            'interview_outcome_label' => $candidate->interview_outcome?->label(),
            'interview_outcome_badge' => $candidate->interview_outcome?->badgeVariant(),
            'requirement_id' => $candidate->recruitment_requirement_id,
            'requirement_number' => (string) $candidate->requirement_number_snapshot,
            'requirement_line_id' => $candidate->recruitment_requirement_line_id,
            'position_title' => (string) $candidate->position_title_snapshot,
            'source' => $candidate->source?->value,
            'source_label' => $candidate->source?->label(),
            'nationality' => $candidate->nationality?->name,
            'has_cv' => $candidate->hasCv(),
            'interview_scheduled_at' => self::formatDateTime($candidate->interview_scheduled_at, $timezone),
            'lock_version' => (int) $candidate->lock_version,
            'created_at' => self::formatDateTime($candidate->created_at, $timezone),
            'parents_valid' => $parentsValid,
            'can_update' => $canUpdate,
            'can_move' => $canMove,
            'can_move_forward' => $canMove && $candidate->stage->allowsForwardMove() && $candidate->interview_outcome === null,
            'can_reject' => $canMove && CandidateWorkflowAuthorization::canReject($candidate),
            'can_select' => $canMove && CandidateWorkflowAuthorization::canSelect($candidate),
            'can_undo_selected' => $canMove && CandidateWorkflowAuthorization::canUndoSelected($candidate),
            'can_reopen' => $canMove
                && $canManage
                && $candidate->stage === CandidateStage::Rejected
                && $parentsValid,
            'can_download_cv' => $canDownload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toShowArray(RecruitmentCandidate $candidate, User $user, string $timezone): array
    {
        $row = self::toIndexRow($candidate, $user, $timezone);
        $requirement = $candidate->requirement;
        $line = $candidate->line;

        $interviewerInternal = $candidate->interviewerUser;
        $transitions = $candidate->stageTransitions
            ->sortBy('created_at')
            ->values()
            ->map(fn (RecruitmentCandidateStageTransition $t): array => self::transitionToArray($t, $timezone))
            ->all();

        return array_merge($row, [
            'notes' => $candidate->notes,
            'rejection_reason' => $candidate->rejection_reason,
            'pre_rejection_stage' => $candidate->pre_rejection_stage,
            'cv_original_file_name' => $candidate->cv_original_file_name,
            'nationality_id' => $candidate->nationality_id,
            'interview' => [
                'scheduled_at' => $candidate->interview_scheduled_at?->copy()->timezone($timezone)->format('Y-m-d\TH:i'),
                'scheduled_at_formatted' => self::formatDateTime($candidate->interview_scheduled_at, $timezone),
                'interviewer_user_id' => $candidate->interviewer_user_id,
                'interviewer_user_name' => $interviewerInternal?->name,
                'external_interviewer_name' => $candidate->external_interviewer_name,
                'mode' => $candidate->interview_mode?->value,
                'mode_label' => $candidate->interview_mode?->label(),
                'location' => $candidate->interview_location,
                'feedback' => $candidate->interview_feedback,
            ],
            'requirement' => $requirement === null ? null : [
                'id' => (int) $requirement->id,
                'requirement_number' => (string) $requirement->requirement_number,
                'status' => $requirement->status->value,
                'status_label' => $requirement->status->label(),
                'assigned_to' => $requirement->assigned_to,
                'assigned_to_name' => $requirement->assignedRecruiter?->name,
                'client_name' => $requirement->client?->name,
                'project_title' => $requirement->project?->title,
            ],
            'line' => $line === null ? null : [
                'id' => (int) $line->id,
                'position_id' => (int) $line->position_id,
                'position_title' => (string) ($line->position?->title ?? $candidate->position_title_snapshot),
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
            ],
            'movement_history' => $transitions,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function transitionToArray(RecruitmentCandidateStageTransition $transition, string $timezone): array
    {
        return [
            'id' => (int) $transition->id,
            'action' => $transition->action->value,
            'action_label' => $transition->action->label(),
            'from_stage' => $transition->from_stage?->value,
            'from_stage_label' => $transition->from_stage?->label(),
            'to_stage' => $transition->to_stage->value,
            'to_stage_label' => $transition->to_stage->label(),
            'from_outcome' => $transition->from_outcome?->value,
            'from_outcome_label' => $transition->from_outcome?->label(),
            'to_outcome' => $transition->to_outcome?->value,
            'to_outcome_label' => $transition->to_outcome?->label(),
            'reason' => $transition->reason,
            'context' => $transition->context,
            'performed_by' => $transition->performed_by,
            'performed_by_name' => $transition->performer?->name,
            'created_at' => self::formatDateTime($transition->created_at, $timezone),
        ];
    }

    /**
     * Compact summary for requirement show.
     *
     * @return array{total: int, by_stage: array<string, int>, selected: int, recent: list<array<string, mixed>>}
     */
    public static function requirementSummary(int $requirementId, int $companyId, User $user, string $timezone): array
    {
        $base = RecruitmentCandidate::query()
            ->forCompany($companyId)
            ->where('recruitment_requirement_id', $requirementId);

        $byStage = [];
        foreach (CandidateStage::cases() as $stage) {
            $byStage[$stage->value] = (clone $base)->where('stage', $stage->value)->count();
        }

        $selected = (clone $base)
            ->where('stage', CandidateStage::Interview->value)
            ->where('interview_outcome', CandidateInterviewOutcome::Selected->value)
            ->count();

        $recent = (clone $base)
            ->with(['nationality:id,name', 'requirement:id,assigned_to,company_id,status'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (RecruitmentCandidate $c): array => self::toIndexRow($c, $user, $timezone))
            ->all();

        return [
            'total' => array_sum($byStage),
            'by_stage' => $byStage,
            'selected' => $selected,
            'recent' => $recent,
        ];
    }

    private static function formatDateTime(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Carbon) {
            try {
                $value = Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $value->copy()->timezone($timezone)->format('d M Y H:i');
    }
}
