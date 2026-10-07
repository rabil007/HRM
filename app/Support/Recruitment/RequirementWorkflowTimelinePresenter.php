<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class RequirementWorkflowTimelinePresenter
{
    /**
     * @return array{
     *     current_stage: string,
     *     current_stage_label: string,
     *     next_expected_action: string|null,
     *     next_expected_action_label: string|null,
     *     events: list<array{
     *         id: string,
     *         key: string,
     *         label: string,
     *         occurred_at: string,
     *         occurred_at_formatted: string,
     *         actor_name: string|null,
     *         reason: string|null,
     *         previous_recruiter_name: string|null,
     *         new_recruiter_name: string|null,
     *         is_current: bool,
     *         state: 'completed'|'current'
     *     }>
     * }
     */
    public static function for(RecruitmentRequirement $requirement, ?User $viewer = null): array
    {
        $timezone = CompanyTimezone::forCompanyId((int) $requirement->company_id);

        /** @var Collection<int, RecruitmentRequirementStatusTransition> $transitions */
        $transitions = RecruitmentRequirementStatusTransition::query()
            ->where('company_id', (int) $requirement->company_id)
            ->where('recruitment_requirement_id', (int) $requirement->id)
            ->with('performer:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $recruiterNames = self::recruiterNamesForTransitions($transitions);

        $events = [];

        foreach ($transitions as $transition) {
            $from = $transition->from_status !== null ? (string) $transition->from_status : null;
            $to = (string) $transition->to_status;

            if (self::isRecruiterReassignmentTransition($from, $to, $transition->reason)) {
                $occurredAt = Carbon::parse($transition->created_at)->timezone($timezone);
                $context = is_array($transition->context) ? $transition->context : [];
                $previousId = isset($context['previous_recruiter_user_id'])
                    ? (int) $context['previous_recruiter_user_id']
                    : null;
                $newId = isset($context['new_recruiter_user_id'])
                    ? (int) $context['new_recruiter_user_id']
                    : null;

                $events[] = [
                    'id' => 'transition_'.$transition->id,
                    'key' => 'recruiter_reassigned',
                    'label' => 'Recruiter reassigned',
                    'occurred_at' => $occurredAt->toIso8601String(),
                    'occurred_at_formatted' => $occurredAt->format('d-m-Y H:i'),
                    'actor_name' => $transition->performer?->name,
                    'reason' => filled($transition->reason) ? (string) $transition->reason : null,
                    'previous_recruiter_name' => $previousId !== null
                        ? ($recruiterNames[$previousId] ?? null)
                        : null,
                    'new_recruiter_name' => $newId !== null
                        ? ($recruiterNames[$newId] ?? null)
                        : null,
                    'is_current' => false,
                    'state' => 'completed',
                ];

                continue;
            }

            $label = self::labelForTransition($from, $to);

            if ($label === null) {
                continue;
            }

            $occurredAt = Carbon::parse($transition->created_at)->timezone($timezone);

            $events[] = [
                'id' => 'transition_'.$transition->id,
                'key' => self::eventKey($from, $to),
                'label' => $label,
                'occurred_at' => $occurredAt->toIso8601String(),
                'occurred_at_formatted' => $occurredAt->format('d-m-Y H:i'),
                'actor_name' => $transition->performer?->name,
                'reason' => filled($transition->reason) ? (string) $transition->reason : null,
                'previous_recruiter_name' => null,
                'new_recruiter_name' => null,
                'is_current' => false,
                'state' => 'completed',
            ];
        }

        // Synthetic Draft fallback only for Requirements that remain Draft with no transitions.
        // Do not invent Draft events for submitted/approved records that skipped a Draft transition.
        if (
            $events === []
            && $requirement->status === RequirementStatus::Draft
            && $requirement->created_at !== null
        ) {
            $createdAt = Carbon::parse($requirement->created_at)->timezone($timezone);
            $events[] = [
                'id' => 'created_'.$requirement->id,
                'key' => 'draft_created',
                'label' => 'Draft created',
                'occurred_at' => $createdAt->toIso8601String(),
                'occurred_at_formatted' => $createdAt->format('d-m-Y H:i'),
                'actor_name' => $requirement->creator?->name,
                'reason' => null,
                'previous_recruiter_name' => null,
                'new_recruiter_name' => null,
                'is_current' => false,
                'state' => 'completed',
            ];
        }

        if ($events !== []) {
            $lastIndex = array_key_last($events);
            $events[$lastIndex]['is_current'] = true;
            $events[$lastIndex]['state'] = 'current';
        }

        $nextAction = self::viewerNextAction($requirement, $viewer);

        return [
            'current_stage' => $requirement->status->value,
            'current_stage_label' => $requirement->status->label(),
            'next_expected_action' => $nextAction,
            'next_expected_action_label' => $nextAction !== null
                ? self::nextActionLabel($nextAction)
                : null,
            'events' => array_values($events),
        ];
    }

    private static function isRecruiterReassignmentTransition(
        ?string $from,
        string $to,
        ?string $reason,
    ): bool {
        if ($from !== RequirementStatus::PendingApproval->value
            || $to !== RequirementStatus::PendingApproval->value) {
            return false;
        }

        if (! filled($reason)) {
            return false;
        }

        return strcasecmp(trim($reason), 'Recruiter reassigned') === 0;
    }

    /**
     * @param  Collection<int, RecruitmentRequirementStatusTransition>  $transitions
     * @return array<int, string>
     */
    private static function recruiterNamesForTransitions(Collection $transitions): array
    {
        $userIds = [];

        foreach ($transitions as $transition) {
            if (! is_array($transition->context)) {
                continue;
            }

            foreach (['previous_recruiter_user_id', 'new_recruiter_user_id'] as $key) {
                if (! isset($transition->context[$key])) {
                    continue;
                }

                $userId = (int) $transition->context[$key];

                if ($userId > 0) {
                    $userIds[$userId] = true;
                }
            }
        }

        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', array_keys($userIds))
            ->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    private static function labelForTransition(?string $from, string $to): ?string
    {
        return match ($to) {
            RequirementStatus::Draft->value => 'Draft created',
            RequirementStatus::PendingApproval->value => $from === RequirementStatus::Returned->value
                ? 'Resubmitted'
                : 'Submitted for approval',
            RequirementStatus::Returned->value => 'Returned',
            RequirementStatus::Open->value => match ($from) {
                RequirementStatus::OnHold->value => 'Resumed',
                RequirementStatus::Completed->value,
                RequirementStatus::Cancelled->value => 'Reopened',
                default => 'Approved / recruitment started',
            },
            RequirementStatus::OnHold->value => 'Put On Hold',
            RequirementStatus::Completed->value => 'Filled / Completed',
            RequirementStatus::Cancelled->value => 'Cancelled',
            default => null,
        };
    }

    private static function eventKey(?string $from, string $to): string
    {
        return match ($to) {
            RequirementStatus::Draft->value => 'draft_created',
            RequirementStatus::PendingApproval->value => $from === RequirementStatus::Returned->value
                ? 'resubmitted'
                : 'submitted',
            RequirementStatus::Returned->value => 'returned',
            RequirementStatus::Open->value => match ($from) {
                RequirementStatus::OnHold->value => 'resumed',
                RequirementStatus::Completed->value,
                RequirementStatus::Cancelled->value => 'reopened',
                default => 'approved',
            },
            RequirementStatus::OnHold->value => 'on_hold',
            RequirementStatus::Completed->value => 'completed',
            RequirementStatus::Cancelled->value => 'cancelled',
            default => $to,
        };
    }

    private static function viewerNextAction(RecruitmentRequirement $requirement, ?User $viewer): ?string
    {
        if ($viewer === null) {
            return null;
        }

        $row = RequirementPresenter::toIndexRow($requirement, null, $viewer);

        return match ($requirement->status) {
            RequirementStatus::Draft => ($row['can_submit'] ?? false) ? 'submit' : null,
            RequirementStatus::PendingApproval => ($row['can_approve'] ?? false) ? 'approve' : null,
            RequirementStatus::Returned => ($row['can_resubmit'] ?? false) ? 'resubmit' : null,
            RequirementStatus::Open => ($row['can_fill'] ?? false) ? 'fill' : null,
            RequirementStatus::OnHold => ($row['can_resume'] ?? false) ? 'resume' : null,
            RequirementStatus::Completed,
            RequirementStatus::Cancelled => ($row['can_repeat'] ?? false) ? 'repeat' : null,
        };
    }

    private static function nextActionLabel(string $action): string
    {
        return match ($action) {
            'submit' => 'Submit for approval',
            'approve' => 'Approve requirement',
            'resubmit' => 'Resubmit for approval',
            'resume' => 'Resume requirement',
            'fill' => 'Mark as filled',
            'repeat' => 'Repeat requirement',
            default => $action,
        };
    }
}
