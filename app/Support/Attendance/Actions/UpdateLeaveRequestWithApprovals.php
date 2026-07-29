<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class UpdateLeaveRequestWithApprovals
{
    private const EDIT_BLOCKED_MESSAGE = 'This leave request can no longer be edited because the approval process has already started.';

    public function __construct(
        private ResolveLeaveApprovalChain $resolveChain,
        private LeaveBalanceManager $leaveBalances,
        private CalculateLeaveRequestDays $calculateDays,
        private LeaveRequestAttachments $attachments,
        private SendLeaveRequestUpdatedEmail $sendUpdatedEmail,
    ) {}

    /**
     * @param  array{
     *     employee_id: int,
     *     leave_type_id: int,
     *     start_date: string,
     *     end_date: string,
     *     reason?: string|null,
     * }  $attributes
     */
    public function handle(
        LeaveRequest $leaveRequest,
        int $companyId,
        array $attributes,
        ?UploadedFile $newAttachment = null,
        bool $removeAttachment = false,
        ?User $actor = null,
    ): LeaveRequest {
        if ((int) $leaveRequest->company_id !== $companyId) {
            abort(404);
        }

        $storedAttachments = null;
        $previousAttachmentsToDelete = null;

        if ($newAttachment !== null) {
            $storedAttachments = $this->attachments->store(
                $newAttachment,
                $companyId,
                (int) $leaveRequest->id,
            );
        }

        try {
            $updated = DB::transaction(function () use (
                $leaveRequest,
                $companyId,
                $attributes,
                $storedAttachments,
                $newAttachment,
                $removeAttachment,
                $actor,
                &$previousAttachmentsToDelete,
            ): LeaveRequest {
                $locked = LeaveRequest::query()
                    ->whereKey($leaveRequest->id)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'leave_request' => 'Only pending leave requests can be updated.',
                    ]);
                }

                $approvals = LeaveRequestApproval::query()
                    ->where('company_id', $companyId)
                    ->where('leave_request_id', $locked->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get();

                if ($this->approvalProcessHasStarted($approvals)) {
                    throw ValidationException::withMessages([
                        'leave_request' => self::EDIT_BLOCKED_MESSAGE,
                    ]);
                }

                $previousApprovalSnapshot = $this->serializeApprovalsForAudit($approvals);
                $previousRequestSnapshot = [
                    'employee_id' => (int) $locked->employee_id,
                    'leave_type_id' => (int) $locked->leave_type_id,
                    'start_date' => $locked->start_date?->toDateString(),
                    'end_date' => $locked->end_date?->toDateString(),
                    'total_days' => (string) $locked->total_days,
                    'reason' => $locked->reason,
                ];

                // Capture attachment state from the locked model, not the stale route model.
                if ($newAttachment !== null || $removeAttachment) {
                    $previousAttachmentsToDelete = $locked->attachments;
                }

                $this->leaveBalances->replacePendingLeaveRequest($locked, [
                    'employee_id' => $attributes['employee_id'],
                    'leave_type_id' => $attributes['leave_type_id'],
                    'start_date' => $attributes['start_date'],
                    'end_date' => $attributes['end_date'],
                ]);

                $payload = [
                    'employee_id' => $attributes['employee_id'],
                    'leave_type_id' => $attributes['leave_type_id'],
                    'start_date' => $attributes['start_date'],
                    'end_date' => $attributes['end_date'],
                    'total_days' => ($this->calculateDays)($attributes['start_date'], $attributes['end_date']),
                    'reason' => $attributes['reason'] ?? null,
                ];

                if ($newAttachment !== null || $removeAttachment) {
                    $payload['attachments'] = $newAttachment !== null ? $storedAttachments : null;
                }

                $locked->fill($payload)->save();

                // Remove unacted snapshot rows only after re-validating none were acted.
                $approvals = LeaveRequestApproval::query()
                    ->where('company_id', $companyId)
                    ->where('leave_request_id', $locked->id)
                    ->lockForUpdate()
                    ->get();

                if ($this->approvalProcessHasStarted($approvals)) {
                    throw ValidationException::withMessages([
                        'leave_request' => self::EDIT_BLOCKED_MESSAGE,
                    ]);
                }

                LeaveRequestApproval::query()
                    ->where('company_id', $companyId)
                    ->where('leave_request_id', $locked->id)
                    ->delete();

                $employee = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $locked->employee_id)
                    ->firstOrFail();

                try {
                    $chain = $this->resolveChain->handle($employee, $companyId);
                    $created = $this->resolveChain->persistSnapshot($locked, $chain);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'leave_request' => $exception->getMessage(),
                    ]);
                }

                if ($created === []) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'Unable to rebuild the leave approval chain for this request.',
                    ]);
                }

                $fresh = $locked->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $locked;

                $this->logChainRebuild(
                    leaveRequest: $fresh,
                    companyId: $companyId,
                    actor: $actor,
                    previousApprovals: $previousApprovalSnapshot,
                    previousRequest: $previousRequestSnapshot,
                    newApprovals: $this->serializeApprovalsForAudit($fresh->approvals),
                );

                return $fresh;
            });
        } catch (Throwable $exception) {
            if ($newAttachment !== null && $storedAttachments !== null) {
                $this->attachments->deleteFromStorage($storedAttachments);
            }

            throw $exception;
        }

        if ($previousAttachmentsToDelete !== null) {
            $this->attachments->deleteFromStorage($previousAttachmentsToDelete);
        }

        DB::afterCommit(function () use ($updated): void {
            try {
                $this->sendUpdatedEmail->handle($updated->fresh(['approvals.approverEmployee.user', 'employee.department', 'leaveType', 'company']) ?? $updated);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $updated;
    }

    /**
     * @param  iterable<int, LeaveRequestApproval>  $approvals
     */
    private function approvalProcessHasStarted(iterable $approvals): bool
    {
        foreach ($approvals as $approval) {
            $status = $approval->status instanceof LeaveRequestApprovalStatus
                ? $approval->status
                : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

            if ($status !== null && $status->isTerminal()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<int, LeaveRequestApproval>  $approvals
     * @return list<array<string, mixed>>
     */
    private function serializeApprovalsForAudit(iterable $approvals): array
    {
        $rows = [];

        foreach ($approvals as $approval) {
            $approverType = $approval->approver_type;

            $rows[] = [
                'sequence' => (int) $approval->sequence,
                'approver_type' => $approverType instanceof LeaveApprovalApproverType
                    ? $approverType->value
                    : (string) $approverType,
                'approver_employee_id' => $approval->approver_employee_id !== null
                    ? (int) $approval->approver_employee_id
                    : null,
                'approver_user_id' => $approval->approver_user_id !== null
                    ? (int) $approval->approver_user_id
                    : null,
                'source_department_id' => $approval->source_department_id !== null
                    ? (int) $approval->source_department_id
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $previousApprovals
     * @param  array<string, mixed>  $previousRequest
     * @param  list<array<string, mixed>>  $newApprovals
     */
    private function logChainRebuild(
        LeaveRequest $leaveRequest,
        int $companyId,
        ?User $actor,
        array $previousApprovals,
        array $previousRequest,
        array $newApprovals,
    ): void {
        $logger = activity()
            ->performedOn($leaveRequest)
            ->withProperties([
                'event' => 'leave_approval_chain_rebuilt',
                'company_id' => $companyId,
                'leave_request_id' => (int) $leaveRequest->id,
                'reason' => 'request edited before approval',
                'previous_approvals' => $previousApprovals,
                'new_approvals' => $newApprovals,
                'previous_request' => $previousRequest,
                'new_request' => [
                    'employee_id' => (int) $leaveRequest->employee_id,
                    'leave_type_id' => (int) $leaveRequest->leave_type_id,
                    'start_date' => $leaveRequest->start_date?->toDateString(),
                    'end_date' => $leaveRequest->end_date?->toDateString(),
                    'total_days' => (string) $leaveRequest->total_days,
                    'reason' => $leaveRequest->reason,
                ],
            ]);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $activity = $logger->log('Leave request approval chain rebuilt before approval');
        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
