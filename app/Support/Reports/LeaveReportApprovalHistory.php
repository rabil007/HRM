<?php

namespace App\Support\Reports;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class LeaveReportApprovalHistory
{
    public const UNAVAILABLE_APPROVER = 'Former / unavailable approver';

    /**
     * @return array{
     *     approval_progress: array{
     *         required_steps: int,
     *         approved_steps: int,
     *         current_status: string,
     *         waiting_for: string|null,
     *         current_sequence: int|null,
     *         label: string
     *     },
     *     approval_chain: list<array<string, mixed>>,
     *     reassignments: list<array<string, mixed>>
     * }
     */
    public static function forRequest(LeaveRequest $leaveRequest, string $timezone, ?User $user = null): array
    {
        $includeReason = $user?->can('audit.view') ?? false;
        $steps = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
            : $leaveRequest->approvals()->where('is_required', true)->orderBy('sequence')->get();

        $required = $steps
            ->filter(fn (LeaveRequestApproval $step): bool => (bool) $step->is_required)
            ->sortBy('sequence')
            ->values();

        $chain = $required
            ->map(fn (LeaveRequestApproval $step): array => self::stepPayload($step, $timezone))
            ->all();

        $reassignments = ($leaveRequest->relationLoaded('approvalReassignments')
            ? $leaveRequest->approvalReassignments
            : $leaveRequest->approvalReassignments()->orderBy('id')->get())
            ->map(fn (LeaveRequestApprovalReassignment $reassignment): array => self::reassignmentPayload(
                $reassignment,
                $timezone,
                $includeReason,
            ))
            ->values()
            ->all();

        return [
            'approval_progress' => self::progress($leaveRequest, $required),
            'approval_chain' => $chain,
            'reassignments' => $reassignments,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $chain
     */
    public static function chainText(array $chain): string
    {
        return collect($chain)
            ->map(function (array $step): string {
                $parts = [
                    $step['sequence'].'. '.($step['policy_step_label'] ?: 'Approval step'),
                    $step['approver_name'],
                    $step['status_label'],
                ];

                if (is_string($step['acted_at'] ?? null) && $step['acted_at'] !== '') {
                    $parts[] = self::displayDateTime($step['acted_at']);
                }

                return implode(' — ', $parts);
            })
            ->implode(' | ');
    }

    public static function reassignmentSummary(array $reassignments): string
    {
        return collect($reassignments)
            ->map(function (array $row): string {
                $date = is_string($row['reassigned_at'] ?? null) && $row['reassigned_at'] !== ''
                    ? ' on '.self::displayDate($row['reassigned_at'])
                    : '';

                return 'Step '.$row['sequence'].': '.$row['from_name'].' → '.$row['to_name'].$date;
            })
            ->implode(' | ');
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $required
     * @return array{
     *     required_steps: int,
     *     approved_steps: int,
     *     current_status: string,
     *     waiting_for: string|null,
     *     current_sequence: int|null,
     *     label: string
     * }
     */
    private static function progress(LeaveRequest $leaveRequest, $required): array
    {
        $requiredCount = $required->count();
        $approvedCount = $required
            ->filter(fn (LeaveRequestApproval $step): bool => self::statusOf($step) === LeaveRequestApprovalStatus::Approved)
            ->count();

        $pending = $required->first(
            fn (LeaveRequestApproval $step): bool => self::statusOf($step) === LeaveRequestApprovalStatus::Pending,
        );
        $rejected = $required->first(
            fn (LeaveRequestApproval $step): bool => self::statusOf($step) === LeaveRequestApprovalStatus::Rejected,
        );

        $waitingFor = $pending instanceof LeaveRequestApproval
            ? self::approverName($pending)
            : null;
        $currentSequence = $pending instanceof LeaveRequestApproval
            ? (int) $pending->sequence
            : null;

        $currentStatus = match (true) {
            $requiredCount === 0 => 'none',
            $rejected instanceof LeaveRequestApproval => 'rejected',
            $leaveRequest->status === 'cancelled' => 'cancelled',
            $approvedCount === $requiredCount => 'approved',
            default => 'pending',
        };

        $label = match (true) {
            $requiredCount === 0 => 'No approval required',
            $rejected instanceof LeaveRequestApproval => 'Rejected by '.self::approverName($rejected),
            $leaveRequest->status === 'cancelled' => 'Cancelled',
            $approvedCount === $requiredCount => $requiredCount.' / '.$requiredCount.' approved',
            $approvedCount === 0 && $waitingFor !== null => 'Pending — waiting for '.$waitingFor,
            default => $approvedCount.' / '.$requiredCount.' approved',
        };

        return [
            'required_steps' => $requiredCount,
            'approved_steps' => $approvedCount,
            'current_status' => $currentStatus,
            'waiting_for' => $waitingFor,
            'current_sequence' => $currentSequence,
            'label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stepPayload(LeaveRequestApproval $step, string $timezone): array
    {
        $status = self::statusOf($step);
        $actedAt = in_array($status, [LeaveRequestApprovalStatus::Pending, LeaveRequestApprovalStatus::Waiting], true)
            ? null
            : self::datetime($step->acted_at, $timezone);

        return [
            'sequence' => (int) $step->sequence,
            'policy_step_label' => $step->policy_step_label !== null ? (string) $step->policy_step_label : null,
            'approver_employee_id' => $step->approver_employee_id !== null ? (int) $step->approver_employee_id : null,
            'approver_name' => self::approverName($step),
            'status' => $status->value,
            'status_label' => $status->label(),
            'acted_at' => $actedAt,
            'is_current_action_step' => $status === LeaveRequestApprovalStatus::Pending,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reassignmentPayload(
        LeaveRequestApprovalReassignment $reassignment,
        string $timezone,
        bool $includeReason,
    ): array {
        $payload = [
            'sequence' => (int) $reassignment->sequence,
            'policy_step_label' => $reassignment->policy_step_label !== null
                ? (string) $reassignment->policy_step_label
                : null,
            'from_name' => self::snapshotName($reassignment->from_approver_name),
            'to_name' => self::snapshotName($reassignment->to_approver_name),
            'reassigned_by' => self::snapshotName($reassignment->reassigned_by_name, 'Unavailable user'),
            'reassigned_at' => self::datetime($reassignment->created_at, $timezone),
        ];

        if ($includeReason) {
            $payload['reason'] = $reassignment->reason !== null ? (string) $reassignment->reason : null;
        }

        return $payload;
    }

    private static function approverName(LeaveRequestApproval $step): string
    {
        $employee = $step->relationLoaded('approverEmployee') ? $step->approverEmployee : null;

        if ($employee !== null && (int) $employee->company_id === (int) $step->company_id && filled($employee->name)) {
            return (string) $employee->name;
        }

        $user = $step->relationLoaded('approverUser') ? $step->approverUser : null;

        if ($user !== null && filled($user->name)) {
            return (string) $user->name;
        }

        return self::UNAVAILABLE_APPROVER;
    }

    private static function snapshotName(mixed $name, string $fallback = self::UNAVAILABLE_APPROVER): string
    {
        $value = is_string($name) ? trim($name) : '';

        return $value !== '' ? $value : $fallback;
    }

    private static function statusOf(LeaveRequestApproval $step): LeaveRequestApprovalStatus
    {
        return $step->status instanceof LeaveRequestApprovalStatus
            ? $step->status
            : LeaveRequestApprovalStatus::from((string) $step->status);
    }

    private static function datetime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->toIso8601String();
    }

    private static function displayDateTime(string $value): string
    {
        return CarbonImmutable::parse($value)->format('d M Y H:i');
    }

    private static function displayDate(string $value): string
    {
        return CarbonImmutable::parse($value)->format('d M Y');
    }
}
