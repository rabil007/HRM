<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

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
        $currentOffer = $candidate->relationLoaded('currentOffer')
            ? $candidate->currentOffer
            : $candidate->currentOffer()->first();
        $canPrepareOffer = $canOwn
            && $parentsValid
            && $user->can('recruitment.candidates.offer.prepare')
            && CandidateWorkflowAuthorization::canPrepareOffer($candidate);
        $canConfirmJoined = CandidateWorkflowAuthorization::canConfirmJoined($user, $candidate);
        $canCorrectJoined = CandidateWorkflowAuthorization::canCorrectJoined($user, $candidate);
        $canUpdateReadiness = CandidateWorkflowAuthorization::canUpdateReadiness($user, $candidate);

        $scheduleInfo = self::resolveJoiningScheduleInfo($candidate, $timezone);

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
            'offer_status' => $currentOffer?->status->value,
            'offer_status_label' => $currentOffer?->status->label(),
            'offer_status_badge' => $currentOffer?->status->badgeVariant(),
            'requirement_id' => $candidate->recruitment_requirement_id,
            'requirement_number' => (string) $candidate->requirement_number_snapshot,
            'requirement_line_id' => $candidate->recruitment_requirement_line_id,
            'position_title' => (string) $candidate->position_title_snapshot,
            'source' => $candidate->source?->value,
            'source_label' => $candidate->source?->label(),
            'nationality' => $candidate->nationality?->name,
            'has_cv' => $candidate->hasCv(),
            'interview_scheduled_at' => self::formatDateTime($candidate->interview_scheduled_at, $timezone),
            'expected_joining_date' => $candidate->expected_joining_date?->toDateString(),
            'actual_joining_date' => $candidate->actual_joining_date?->toDateString(),
            'joining_readiness_status' => $candidate->joining_readiness_status?->value,
            'joining_readiness_label' => $candidate->joining_readiness_status?->label(),
            'joining_readiness_badge' => $candidate->joining_readiness_status?->badgeVariant(),
            'joining_schedule_urgency' => $scheduleInfo['urgency'],
            'joining_schedule_days_diff' => $scheduleInfo['days_diff'],
            'joining_schedule_label' => $scheduleInfo['label'],
            'lock_version' => (int) $candidate->lock_version,
            'created_at' => self::formatDateTime($candidate->created_at, $timezone),
            'parents_valid' => $parentsValid,
            'can_update' => $canUpdate,
            'can_move' => $canMove,
            'can_move_forward' => $canMove && $candidate->stage->allowsForwardMove() && $candidate->interview_outcome === null,
            'can_reject' => $canMove && CandidateWorkflowAuthorization::canReject($candidate),
            'can_select' => $canMove && CandidateWorkflowAuthorization::canSelect($candidate),
            'can_undo_selected' => $canMove && CandidateWorkflowAuthorization::canUndoSelected($candidate),
            'can_prepare_offer' => $canPrepareOffer,
            'can_reopen' => $canMove
                && $canManage
                && $candidate->stage === CandidateStage::Rejected
                && $parentsValid,
            'can_download_cv' => $canDownload,
            'can_update_readiness' => $canUpdateReadiness,
            'can_confirm_joined' => $canConfirmJoined,
            'can_correct_joined' => $canCorrectJoined,
            'can_convert' => self::canConvert($user, $candidate),
            'conversion_status' => self::resolveConversionStatus($candidate),
            'employee_id' => ($user->can('employees.view') && $candidate->employee_id !== null && EmployeeVisibilityScope::canAccessId($user, (int) $candidate->employee_id, (int) $candidate->company_id)) ? $candidate->employee_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toShowArray(
        RecruitmentCandidate $candidate,
        User $user,
        string $timezone,
        bool $includeMovementHistory = false,
    ): array {
        $row = self::toIndexRow($candidate, $user, $timezone);
        $requirement = $candidate->requirement;
        $line = $candidate->line;

        $interviewerInternal = $candidate->interviewerUser;
        $transitions = [];

        if ($includeMovementHistory) {
            $transitions = $candidate->stageTransitions
                ->sortBy('created_at')
                ->values()
                ->map(fn (RecruitmentCandidateStageTransition $t): array => self::transitionToArray($t, $timezone))
                ->all();
        }

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
                'salary_min' => $line->salary_min !== null ? (string) $line->salary_min : null,
                'salary_max' => $line->salary_max !== null ? (string) $line->salary_max : null,
                'salary_currency_code' => $line->salary_currency_code,
            ],
            'current_offer' => CandidateOfferPresenter::detail(
                $candidate->relationLoaded('currentOffer')
                    ? $candidate->currentOffer
                    : $candidate->currentOffer()->with(['sender:id,name', 'acceptor:id,name', 'rejector:id,name'])->first(),
                $candidate,
                $user,
                $timezone,
            ),
            'joining' => [
                'expected_joining_date' => $candidate->expected_joining_date?->toDateString(),
                'actual_joining_date' => $candidate->actual_joining_date?->toDateString(),
                'joined_at' => self::formatDateTime($candidate->joined_at, $timezone),
                'joined_by' => $candidate->joined_by,
                'joined_by_name' => $candidate->relationLoaded('joinedByUser')
                    ? $candidate->joinedByUser?->name
                    : $candidate->joinedByUser()->value('name'),
                'readiness_status' => $candidate->joining_readiness_status?->value,
                'readiness_status_label' => $candidate->joining_readiness_status?->label(),
                'readiness_status_badge' => $candidate->joining_readiness_status?->badgeVariant(),
                'readiness_notes' => $candidate->joining_readiness_notes,
                'blocker_notes' => $candidate->joining_blocker_notes,
                'schedule_urgency' => $row['joining_schedule_urgency'],
                'schedule_days_diff' => $row['joining_schedule_days_diff'],
                'schedule_label' => $row['joining_schedule_label'],
                'can_update_readiness' => $row['can_update_readiness'],
                'can_confirm_joined' => $row['can_confirm_joined'],
                'can_correct_joined' => $row['can_correct_joined'],
                'can_convert' => $row['can_convert'],
                'conversion_status' => $row['conversion_status'],
                'linked_employee' => self::resolveLinkedEmployee($candidate, $user, (int) $candidate->company_id),
            ],
            'conversion_status' => $row['conversion_status'],
            'can_convert' => $row['can_convert'],
            'linked_employee' => self::resolveLinkedEmployee($candidate, $user, (int) $candidate->company_id),
            'offer_history' => $includeMovementHistory && $candidate->relationLoaded('offers')
                ? CandidateOfferPresenter::history($candidate, $timezone)
                : [],
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

    /**
     * @return array{
     *     urgency: 'overdue'|'today'|'upcoming'|null,
     *     days_diff: int|null,
     *     label: string|null,
     * }
     */
    public static function resolveJoiningScheduleInfo(RecruitmentCandidate $candidate, string $timezone): array
    {
        if ($candidate->stage !== CandidateStage::Joining || $candidate->expected_joining_date === null) {
            return [
                'urgency' => null,
                'days_diff' => null,
                'label' => null,
            ];
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dateStr = CandidateOfferDateValidation::extractDateOnlyString($candidate->expected_joining_date);

        if ($dateStr === null) {
            return [
                'urgency' => null,
                'days_diff' => null,
                'label' => null,
            ];
        }

        $expected = CarbonImmutable::createFromFormat('!Y-m-d', $dateStr, $timezone);

        $diff = (int) round($today->floatDiffInDays($expected, false));

        if ($diff < 0) {
            $daysOverdue = abs($diff);

            return [
                'urgency' => 'overdue',
                'days_diff' => $daysOverdue,
                'label' => "Overdue by {$daysOverdue} day".($daysOverdue === 1 ? '' : 's'),
            ];
        }

        if ($diff === 0) {
            return [
                'urgency' => 'today',
                'days_diff' => 0,
                'label' => 'Joining today',
            ];
        }

        return [
            'urgency' => 'upcoming',
            'days_diff' => $diff,
            'label' => "Joining in {$diff} day".($diff === 1 ? '' : 's'),
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

    /**
     * @return array{id: int|null, name: string|null, employee_no: string|null, can_view: bool}|null
     */
    public static function resolveLinkedEmployee(?RecruitmentCandidate $candidate, User $user, int $companyId): ?array
    {
        if ($candidate === null || $candidate->employee_id === null) {
            return null;
        }

        $employee = $candidate->relationLoaded('employee')
            ? $candidate->employee
            : $candidate->employee()->first();

        if ($employee === null) {
            return null;
        }

        $canView = $user->can('employees.view')
            && EmployeeVisibilityScope::canAccess($user, $employee, $companyId);

        if (! $canView) {
            return [
                'id' => null,
                'name' => null,
                'employee_no' => null,
                'can_view' => false,
            ];
        }

        return [
            'id' => (int) $employee->id,
            'name' => (string) $employee->name,
            'employee_no' => (string) $employee->employee_no,
            'can_view' => true,
        ];
    }

    public static function resolveConversionStatus(RecruitmentCandidate $candidate): string
    {
        if ($candidate->employee_id !== null) {
            return 'converted';
        }

        if ($candidate->stage === CandidateStage::Joined) {
            return 'pending';
        }

        return 'not_applicable';
    }

    public static function canConvert(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.view')
            && $user->can('employees.create')
            && $user->can('recruitment.candidates.convert')
            && $candidate->stage === CandidateStage::Joined
            && $candidate->employee_id === null;
    }
}
