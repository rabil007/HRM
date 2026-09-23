<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementDeadlineHealth;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class RequirementPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toIndexRow(
        RecruitmentRequirement $requirement,
        ?CarbonInterface $today = null,
        ?User $user = null,
    ): array {
        $today ??= Carbon::today();
        $totalHeadcount = (int) $requirement->lines->sum('required_headcount');
        $deadlineHealth = self::computeDeadlineHealth($requirement, $today);
        $daysDiff = self::computeDaysDiff($requirement, $today);
        $daysLabel = self::computeDaysLabel($requirement, $today, $daysDiff);

        $positionsSummary = $requirement->lines->map(fn (RecruitmentRequirementLine $line): array => [
            'id' => (int) $line->id,
            'position_id' => (int) $line->position_id,
            'position_title' => (string) ($line->position?->title ?? 'Unknown'),
            'required_headcount' => (int) $line->required_headcount,
            'status' => $line->status->value,
        ])->all();

        $canUpdate = $user ? (bool) $user->can('recruitment.requirements.update') : true;
        $canClose = $user ? (bool) $user->can('recruitment.requirements.close') : true;
        $canCancelPerm = $user ? (bool) $user->can('recruitment.requirements.cancel') : true;
        $canReopenPerm = $user ? (bool) $user->can('recruitment.requirements.reopen') : true;
        $canCreate = $user ? (bool) $user->can('recruitment.requirements.create') : true;
        $canView = $user ? (bool) $user->can('recruitment.requirements.view') : true;

        $isEditable = $requirement->status->isEditable();
        $isHistory = in_array($requirement->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true);

        return [
            'id' => (int) $requirement->id,
            'requirement_number' => (string) $requirement->requirement_number,
            'client_id' => (int) $requirement->client_id,
            'client_name' => (string) ($requirement->client?->name ?? '—'),
            'project_id' => $requirement->project_id !== null ? (int) $requirement->project_id : null,
            'project_title' => $requirement->project?->title,
            'client_reference_number' => $requirement->client_reference_number,
            'location' => $requirement->location,
            'priority' => $requirement->priority->value,
            'priority_label' => $requirement->priority->label(),
            'priority_badge' => $requirement->priority->badgeVariant(),
            'status' => $requirement->status->value,
            'status_label' => $requirement->status->label(),
            'status_badge' => $requirement->status->badgeVariant(),
            'assigned_to' => $requirement->assigned_to !== null ? (int) $requirement->assigned_to : null,
            'assigned_recruiter_name' => $requirement->assignedRecruiter?->name,
            'request_received_date' => $requirement->request_received_date?->format('Y-m-d'),
            'request_received_date_formatted' => $requirement->request_received_date?->format('d-m-Y') ?? '—',
            'required_by_date' => $requirement->required_by_date?->format('Y-m-d'),
            'required_by_date_formatted' => $requirement->required_by_date?->format('d-m-Y') ?? '—',
            'deadline_health' => $deadlineHealth?->value,
            'deadline_health_label' => $deadlineHealth?->label(),
            'deadline_health_badge' => $deadlineHealth?->badgeVariant(),
            'days_remaining_or_overdue' => $daysDiff,
            'days_label' => $daysLabel,
            'total_headcount' => $totalHeadcount,
            'positions_summary' => $positionsSummary,
            'positions_count' => count($positionsSummary),
            'repeated_from_id' => $requirement->repeated_from_id !== null ? (int) $requirement->repeated_from_id : null,
            'repeated_from_number' => $requirement->repeatedFrom?->requirement_number,
            'next_action' => self::computeNextAction($requirement, $deadlineHealth),
            'can_edit' => $canUpdate && $isEditable,
            'can_open' => $canUpdate && $requirement->status === RequirementStatus::Draft,
            'can_hold' => $canUpdate && $requirement->status === RequirementStatus::Open,
            'can_resume' => $canUpdate && $requirement->status === RequirementStatus::OnHold,
            'can_extend' => $canUpdate && in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Open, RequirementStatus::OnHold], true),
            'can_extend_deadline' => $canUpdate && in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Open, RequirementStatus::OnHold], true),
            'can_change_headcount' => $canUpdate && in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Open, RequirementStatus::OnHold], true),
            'can_fill' => $canClose && in_array($requirement->status, [RequirementStatus::Open, RequirementStatus::OnHold], true),
            'can_cancel' => $canCancelPerm && in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Open, RequirementStatus::OnHold], true),
            'can_reopen' => $canReopenPerm && $isHistory,
            'can_repeat' => $canView && $canCreate && $isHistory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toShow(
        RecruitmentRequirement $requirement,
        ?CarbonInterface $today = null,
        ?User $user = null,
    ): array {
        $today ??= Carbon::today();
        $base = self::toIndexRow($requirement, $today, $user);

        $lines = $requirement->lines->map(function (RecruitmentRequirementLine $line): array {
            return [
                'id' => (int) $line->id,
                'recruitment_requirement_id' => (int) $line->recruitment_requirement_id,
                'position_id' => (int) $line->position_id,
                'position_title' => (string) ($line->position?->title ?? '—'),
                'department_name' => $line->position?->department?->name,
                'grade' => $line->position?->grade,
                'required_headcount' => (int) $line->required_headcount,
                'line_notes' => $line->line_notes,
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
                'status_badge' => $line->status->badgeVariant(),
            ];
        })->all();

        $attachments = $requirement->attachments->map(function (RecruitmentRequirementAttachment $att): array {
            return [
                'id' => (int) $att->id,
                'original_file_name' => (string) $att->original_file_name,
                'mime_type' => (string) $att->mime_type,
                'file_size_bytes' => (int) $att->file_size_bytes,
                'file_size_formatted' => self::formatBytes((int) $att->file_size_bytes),
                'uploader_name' => $att->uploader?->name,
                'created_at_formatted' => $att->created_at?->format('d-m-Y H:i') ?? '—',
            ];
        })->all();

        return array_merge($base, [
            'notes' => $requirement->notes,
            'cancellation_reason' => $requirement->cancellation_reason,
            'opened_at_formatted' => $requirement->opened_at?->format('d-m-Y H:i'),
            'completed_at_formatted' => $requirement->completed_at?->format('d-m-Y H:i'),
            'cancelled_at_formatted' => $requirement->cancelled_at?->format('d-m-Y H:i'),
            'created_at_formatted' => $requirement->created_at?->format('d-m-Y H:i'),
            'creator_name' => $requirement->creator?->name,
            'updater_name' => $requirement->updater?->name,
            'lines' => $lines,
            'attachments' => $attachments,
            'progress' => [
                'filled' => 0, // Extension point for Phase 2 Candidates
                'target' => $base['total_headcount'],
                'percentage' => 0,
                'is_target_reached' => false,
            ],
        ]);
    }

    public static function computeDeadlineHealth(
        RecruitmentRequirement $requirement,
        CarbonInterface $today,
    ): ?RequirementDeadlineHealth {
        if (in_array($requirement->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
            return null;
        }

        if ($requirement->required_by_date === null) {
            return null;
        }

        $requiredBy = Carbon::parse($requirement->required_by_date)->startOfDay();
        $currentDay = $today->copy()->startOfDay();

        if ($requiredBy->lt($currentDay)) {
            return RequirementDeadlineHealth::Overdue;
        }

        if ($requiredBy->lte($currentDay->copy()->addDays(7))) {
            return RequirementDeadlineHealth::DueSoon;
        }

        return RequirementDeadlineHealth::OnTrack;
    }

    public static function computeDaysDiff(
        RecruitmentRequirement $requirement,
        CarbonInterface $today,
    ): ?int {
        if ($requirement->required_by_date === null) {
            return null;
        }

        $requiredBy = Carbon::parse($requirement->required_by_date)->startOfDay();
        $currentDay = $today->copy()->startOfDay();

        return (int) $currentDay->diffInDays($requiredBy, false);
    }

    public static function computeDaysLabel(
        RecruitmentRequirement $requirement,
        CarbonInterface $today,
        ?int $diffDays,
    ): string {
        if ($diffDays === null) {
            return '—';
        }

        if (in_array($requirement->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
            return $requirement->status->label();
        }

        if ($diffDays < 0) {
            $abs = abs($diffDays);

            return $abs === 1 ? '1 day overdue' : "{$abs} days overdue";
        }

        if ($diffDays === 0) {
            return 'Due today';
        }

        return $diffDays === 1 ? '1 day remaining' : "{$diffDays} days remaining";
    }

    public static function computeNextAction(
        RecruitmentRequirement $requirement,
        ?RequirementDeadlineHealth $health,
    ): string {
        return match ($requirement->status) {
            RequirementStatus::Draft => 'open',
            RequirementStatus::Open => $health === RequirementDeadlineHealth::Overdue ? 'extend' : 'fill',
            RequirementStatus::OnHold => 'resume',
            RequirementStatus::Completed => 'repeat',
            RequirementStatus::Cancelled => 'repeat',
        };
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
