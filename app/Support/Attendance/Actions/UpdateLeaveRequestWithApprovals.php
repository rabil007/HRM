<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
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
        private SendLeaveRequestSubmittedEmail $sendSubmittedEmail,
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
    ): LeaveRequest {
        if ((int) $leaveRequest->company_id !== $companyId) {
            abort(404);
        }

        $previousApproverUserId = null;
        $oldAttachments = $leaveRequest->attachments;
        $storedAttachments = null;
        $shouldDeleteOldAttachments = false;

        if ($newAttachment !== null) {
            $storedAttachments = $this->attachments->store(
                $newAttachment,
                $companyId,
                (int) $leaveRequest->id,
            );
            $shouldDeleteOldAttachments = true;
        } elseif ($removeAttachment) {
            $storedAttachments = null;
            $shouldDeleteOldAttachments = true;
        }

        try {
            $updated = DB::transaction(function () use (
                $leaveRequest,
                $companyId,
                $attributes,
                $storedAttachments,
                $newAttachment,
                $removeAttachment,
                &$previousApproverUserId,
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

                $previousPending = $approvals->first(
                    fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending,
                );
                $previousApproverUserId = $previousPending?->approver_user_id;

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
                    $payload['attachments'] = $storedAttachments;
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

                return $locked->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $locked;
            });
        } catch (Throwable $exception) {
            if ($newAttachment !== null && $storedAttachments !== null) {
                $this->attachments->deleteFromStorage($storedAttachments);
            }

            throw $exception;
        }

        if ($shouldDeleteOldAttachments) {
            $this->attachments->deleteFromStorage($oldAttachments);
        }

        $newPending = $updated->approvals
            ->first(fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending);

        $shouldNotify = $newPending !== null
            && (int) $newPending->approver_user_id !== (int) $previousApproverUserId;

        if ($shouldNotify) {
            DB::afterCommit(function () use ($updated): void {
                try {
                    $this->sendSubmittedEmail->handle($updated->fresh() ?? $updated);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

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
}
