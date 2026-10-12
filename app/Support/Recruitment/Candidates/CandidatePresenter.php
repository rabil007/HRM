<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateInterviewMode;
use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Recruitment\Candidates\Actions\CorrectCandidateStage;
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
        $daysInStage = self::resolveDaysInStage($candidate, $timezone);

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
            'interview_feedback' => $candidate->interview_feedback,
            'expected_joining_date' => $candidate->expected_joining_date?->toDateString(),
            'actual_joining_date' => $candidate->actual_joining_date?->toDateString(),
            'joining_readiness_status' => $candidate->joining_readiness_status?->value,
            'joining_readiness_label' => $candidate->joining_readiness_status?->label(),
            'joining_readiness_badge' => $candidate->joining_readiness_status?->badgeVariant(),
            'joining_readiness_notes' => $candidate->joining_readiness_notes,
            'joining_blocker_notes' => $candidate->joining_blocker_notes,
            'joining_schedule_urgency' => $scheduleInfo['urgency'],
            'joining_schedule_days_diff' => $scheduleInfo['days_diff'],
            'joining_schedule_label' => $scheduleInfo['label'],
            'days_in_stage' => $daysInStage,
            'lock_version' => (int) $candidate->lock_version,
            'created_at' => self::formatDateTime($candidate->created_at, $timezone),
            'parents_valid' => $parentsValid,
            'can_update' => $canUpdate,
            'can_update_interview' => $canUpdate && CandidateWorkflowAuthorization::canEditInterviewDetails($candidate),
            'can_move' => $canMove,
            'can_move_forward' => $canMove && $candidate->stage->allowsForwardMove() && $candidate->interview_outcome === null,
            'can_correct_stage' => CandidateWorkflowAuthorization::canCorrectStage($user, $candidate),
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
            'move_destinations' => self::resolveMoveDestinations(
                $candidate,
                $user,
                $canMove,
                $canPrepareOffer,
                $canConfirmJoined,
                $canUpdateReadiness,
                $canCorrectJoined,
            ),
            'employee_id' => ($user->can('employees.view') && $candidate->employee_id !== null && EmployeeVisibilityScope::canAccessId($user, (int) $candidate->employee_id, (int) $candidate->company_id)) ? $candidate->employee_id : null,
        ];
    }

    /**
     * Server-authoritative destinations for drag-and-drop / Move to Stage menus.
     *
     * @return list<array{stage: string, kind: string, label: string, blocked_reason: string|null}>
     */
    public static function resolveMoveDestinations(
        RecruitmentCandidate $candidate,
        User $user,
        bool $canMove,
        bool $canPrepareOffer,
        bool $canConfirmJoined,
        bool $canUpdateReadiness,
        bool $canCorrectJoined,
    ): array {
        $destinations = [];
        $parentsValid = CandidateWorkflowAuthorization::hasValidParentsForActions($candidate);
        $currentOffer = $candidate->relationLoaded('currentOffer')
            ? $candidate->currentOffer
            : $candidate->currentOffer()->first();

        if ($canMove && $parentsValid && $candidate->stage->allowsForwardMove() && $candidate->interview_outcome === null) {
            $next = $candidate->stage->nextStage();
            if ($next !== null) {
                $destinations[] = [
                    'stage' => $next->value,
                    'kind' => 'forward',
                    'label' => 'Move to '.$next->label(),
                    'blocked_reason' => null,
                ];
            }
        }

        if (CandidateWorkflowAuthorization::canCorrectStage($user, $candidate)) {
            foreach (CorrectCandidateStage::allowedDestinations($candidate->stage) as $stage) {
                $destinations[] = [
                    'stage' => $stage->value,
                    'kind' => 'backward',
                    'label' => 'Correct to '.$stage->label(),
                    'blocked_reason' => null,
                ];
            }
        }

        if ($candidate->stage === CandidateStage::Interview) {
            if ($canPrepareOffer) {
                $destinations[] = [
                    'stage' => CandidateStage::OfferJol->value,
                    'kind' => 'prepare_offer',
                    'label' => 'Prepare Offer/JOL',
                    'blocked_reason' => null,
                ];
            } elseif ($canMove && $candidate->interview_outcome === null) {
                $destinations[] = [
                    'stage' => CandidateStage::OfferJol->value,
                    'kind' => 'select_required',
                    'label' => 'Offer/JOL',
                    'blocked_reason' => 'Mark the candidate as Selected before preparing an offer.',
                ];
            } elseif ($currentOffer !== null) {
                $destinations[] = [
                    'stage' => CandidateStage::OfferJol->value,
                    'kind' => 'blocked',
                    'label' => 'Offer/JOL',
                    'blocked_reason' => 'An offer already exists. Open the candidate to continue the offer workflow.',
                ];
            }
        }

        if ($candidate->stage === CandidateStage::OfferJol) {
            if ($canConfirmJoined) {
                $destinations[] = [
                    'stage' => CandidateStage::Joined->value,
                    'kind' => 'confirm_joined',
                    'label' => 'Confirm Joined',
                    'blocked_reason' => null,
                ];
            } else {
                $reason = 'Complete offer acceptance and joining readiness before confirming joined.';
                if ($currentOffer === null) {
                    $reason = 'Prepare and accept an offer before confirming joined.';
                } elseif ($currentOffer->status === CandidateOfferStatus::Draft) {
                    $reason = 'Complete and send the draft offer, then record acceptance.';
                } elseif ($currentOffer->status === CandidateOfferStatus::Sent) {
                    $reason = 'Record offer acceptance before joining confirmation.';
                } elseif ($canUpdateReadiness) {
                    $reason = 'Update joining readiness to Ready before confirming joined.';
                }

                $destinations[] = [
                    'stage' => CandidateStage::Joined->value,
                    'kind' => 'blocked',
                    'label' => 'Joined',
                    'blocked_reason' => $reason,
                ];
            }

            $destinations[] = [
                'stage' => CandidateStage::Interview->value,
                'kind' => 'blocked',
                'label' => 'Interview',
                'blocked_reason' => 'Offer/JOL cannot move back to Interview with a free correction. Revise or manage the offer through the dedicated workflow.',
            ];
        }

        if ($candidate->stage === CandidateStage::Joined) {
            if ($canCorrectJoined) {
                $destinations[] = [
                    'stage' => CandidateStage::OfferJol->value,
                    'kind' => 'correct_joined',
                    'label' => 'Undo Joined',
                    'blocked_reason' => null,
                ];
            } elseif ($candidate->employee_id !== null) {
                $destinations[] = [
                    'stage' => CandidateStage::OfferJol->value,
                    'kind' => 'blocked',
                    'label' => 'Offer/JOL',
                    'blocked_reason' => 'Cannot undo joining after an employee record is linked.',
                ];
            }
        }

        if (
            $candidate->stage === CandidateStage::Rejected
            && $canMove
            && $user->can('recruitment.candidates.manage')
            && $parentsValid
        ) {
            $destinations[] = [
                'stage' => 'pre_rejection',
                'kind' => 'reopen',
                'label' => 'Reopen to previous stage',
                'blocked_reason' => null,
            ];
        }

        return $destinations;
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
                ->map(fn (RecruitmentCandidateStageTransition $t): array => self::transitionToArray(
                    $t,
                    $timezone,
                    $user,
                    (int) $candidate->company_id,
                ))
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
                'notes' => $candidate->interview_notes,
                'feedback' => $candidate->interview_feedback,
                'can_edit' => (bool) ($row['can_update_interview'] ?? false),
                'edit_blocked_reason' => CandidateWorkflowAuthorization::interviewEditBlockedReason($candidate),
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
    public static function transitionToArray(
        RecruitmentCandidateStageTransition $transition,
        string $timezone,
        ?User $user = null,
        ?int $companyId = null,
    ): array {
        $action = $transition->action;
        $context = $transition->context;
        $reason = $transition->reason;

        $isEmployeeAction = in_array($action, [
            CandidateTransitionAction::EmployeeConverted,
            CandidateTransitionAction::EmployeeLinked,
        ], true) || (is_array($context) && (array_key_exists('employee_id', $context) || array_key_exists('employee_no', $context)));

        $canViewEmployee = true;

        if ($isEmployeeAction) {
            $employeeId = is_array($context) && ! empty($context['employee_id'])
                ? (int) $context['employee_id']
                : (int) ($transition->candidate?->employee_id ?? 0);

            $resolvedCompanyId = $companyId ?? (int) ($transition->company_id ?? $transition->candidate?->company_id ?? 0);

            $canViewEmployee = false;
            if ($user !== null && $resolvedCompanyId > 0 && $user->can('employees.view')) {
                if ($employeeId > 0) {
                    $canViewEmployee = EmployeeVisibilityScope::canAccessId($user, $employeeId, $resolvedCompanyId);
                }
            }

            if (! $canViewEmployee) {
                if ($action === CandidateTransitionAction::EmployeeConverted) {
                    $reason = 'Candidate converted to employee';
                } elseif ($action === CandidateTransitionAction::EmployeeLinked) {
                    $reason = 'Linked to existing employee';
                } else {
                    if ($reason !== null && is_array($context) && ! empty($context['employee_no'])) {
                        $reason = str_replace(['#'.$context['employee_no'], (string) $context['employee_no']], '', $reason);
                    }
                }

                if (is_array($context)) {
                    if (array_key_exists('employee_id', $context)) {
                        $context['employee_id'] = null;
                    }
                    if (array_key_exists('employee_no', $context)) {
                        $context['employee_no'] = null;
                    }
                    if (array_key_exists('employee_name', $context)) {
                        $context['employee_name'] = null;
                    }
                    if (array_key_exists('employee_url', $context)) {
                        $context['employee_url'] = null;
                    }
                    if (array_key_exists('reason', $context)) {
                        $context['reason'] = null;
                    }
                }
            }
        }

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
            'reason' => $reason,
            'context' => self::sanitizeTransitionContext(
                is_array($context) ? $context : null,
                $timezone,
                $canViewEmployee,
            ),
            'performed_by' => $transition->performed_by,
            'performed_by_name' => $transition->performer?->name,
            'created_at' => self::formatDateTime($transition->created_at, $timezone),
        ];
    }

    /**
     * Expose only approved transition context fields for recruitment movement history.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    private static function sanitizeTransitionContext(?array $context, string $timezone, bool $includeEmployeeFields = true): ?array
    {
        if ($context === null || $context === []) {
            return null;
        }

        $sanitized = [];

        if (array_key_exists('interview_cycle_snapshot', $context) && is_array($context['interview_cycle_snapshot'])) {
            $sanitized['interview_cycle_snapshot'] = self::presentInterviewCycleSnapshot(
                $context['interview_cycle_snapshot'],
                $timezone,
            );
        }

        if (array_key_exists('prior_interview_cycle_snapshot', $context) && is_array($context['prior_interview_cycle_snapshot'])) {
            $sanitized['prior_interview_cycle_snapshot'] = self::presentInterviewCycleSnapshot(
                $context['prior_interview_cycle_snapshot'],
                $timezone,
            );
        }

        if (array_key_exists('interview_feedback', $context) && is_array($context['interview_feedback'])) {
            $sanitized['interview_feedback'] = [
                'from' => isset($context['interview_feedback']['from'])
                    ? (is_string($context['interview_feedback']['from']) ? $context['interview_feedback']['from'] : null)
                    : null,
                'to' => isset($context['interview_feedback']['to'])
                    ? (is_string($context['interview_feedback']['to']) ? $context['interview_feedback']['to'] : null)
                    : null,
            ];
        }

        foreach ([
            'cleared_selection',
            'cleared_interview_outcome',
            'notes',
            'revision_number',
            'offer_id',
            'offer_status',
            'readiness_status',
            'expected_joining_date',
            'actual_joining_date',
        ] as $key) {
            if (array_key_exists($key, $context)) {
                $sanitized[$key] = $context[$key];
            }
        }

        if ($includeEmployeeFields) {
            foreach (['employee_id', 'employee_no', 'employee_name', 'employee_url'] as $key) {
                if (array_key_exists($key, $context)) {
                    $sanitized[$key] = $context[$key];
                }
            }
        }

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function presentInterviewCycleSnapshot(array $snapshot, string $timezone): array
    {
        $mode = null;
        $modeLabel = null;

        if (isset($snapshot['interview_mode']) && is_string($snapshot['interview_mode']) && $snapshot['interview_mode'] !== '') {
            $mode = $snapshot['interview_mode'];
            $modeEnum = CandidateInterviewMode::tryFrom($mode);
            $modeLabel = $modeEnum?->label();
        }

        $outcome = null;
        $outcomeLabel = null;

        if (isset($snapshot['interview_outcome']) && is_string($snapshot['interview_outcome']) && $snapshot['interview_outcome'] !== '') {
            $outcome = $snapshot['interview_outcome'];
            $outcomeEnum = CandidateInterviewOutcome::tryFrom($outcome);
            $outcomeLabel = $outcomeEnum?->label();
        }

        $scheduledAt = null;
        $scheduledRaw = $snapshot['interview_scheduled_at'] ?? null;

        if (is_string($scheduledRaw) && $scheduledRaw !== '') {
            try {
                $scheduledAt = self::formatDateTime(Carbon::parse($scheduledRaw, $timezone), $timezone)
                    ?? self::formatDateTime(Carbon::parse($scheduledRaw), $timezone);
            } catch (\Throwable) {
                $scheduledAt = $scheduledRaw;
            }
        }

        return [
            'interview_scheduled_at' => $scheduledAt,
            'interviewer_user_id' => isset($snapshot['interviewer_user_id'])
                ? (is_numeric($snapshot['interviewer_user_id']) ? (int) $snapshot['interviewer_user_id'] : null)
                : null,
            'external_interviewer_name' => isset($snapshot['external_interviewer_name']) && is_string($snapshot['external_interviewer_name'])
                ? $snapshot['external_interviewer_name']
                : null,
            'interview_mode' => $mode,
            'interview_mode_label' => $modeLabel,
            'interview_location' => isset($snapshot['interview_location']) && is_string($snapshot['interview_location'])
                ? $snapshot['interview_location']
                : null,
            'interview_notes' => isset($snapshot['interview_notes']) && is_string($snapshot['interview_notes'])
                ? $snapshot['interview_notes']
                : null,
            'interview_feedback' => isset($snapshot['interview_feedback']) && is_string($snapshot['interview_feedback'])
                ? $snapshot['interview_feedback']
                : null,
            'interview_outcome' => $outcome,
            'interview_outcome_label' => $outcomeLabel,
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
        foreach (CandidateStage::activeStages() as $stage) {
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
     * Calendar days since the candidate entered the current stage (latest transition, else created_at).
     */
    public static function resolveDaysInStage(RecruitmentCandidate $candidate, string $timezone): int
    {
        $raw = $candidate->getAttribute('stage_transitions_max_created_at');

        if ($raw === null) {
            $enteredAt = $candidate->created_at ?? CarbonImmutable::now($timezone);
        } else {
            $enteredAt = CarbonImmutable::parse((string) $raw, config('app.timezone'))
                ->timezone($timezone);
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $enteredDay = $enteredAt->startOfDay();

        return max(0, (int) round($enteredDay->floatDiffInDays($today, false)));
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
        if (
            ! CandidateWorkflowAuthorization::isAwaitingJoiningConfirmation($candidate)
            || $candidate->expected_joining_date === null
        ) {
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
