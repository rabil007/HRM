<?php

namespace App\Support\Attendance\Actions;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\AssertLeaveRequestOverlap;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use App\Support\Attendance\ValidateLeaveRequestDateRange;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SubmitLeaveRequestWithApprovals
{
    public function __construct(
        private ResolveLeaveApprovalChain $resolveChain,
        private LeaveBalanceManager $leaveBalances,
        private CalculateLeaveRequestDays $calculateDays,
        private AssertLeaveRequestOverlap $assertOverlap,
        private LeaveRequestAttachments $attachments,
        private ValidateLeaveRequestDateRange $validateDateRange,
        private AssertLeaveApprovalWorkflowInvariant $assertInvariant,
        private SendLeaveRequestSubmittedEmail $sendSubmittedEmail,
    ) {}

    /**
     * Atomically create a pending leave request with overlap/balance revalidation,
     * approval snapshot, optional attachment, and post-commit notification.
     *
     * Client-supplied company_id is ignored — only the trusted $companyId is used.
     *
     * @param  array{
     *     employee_id: int,
     *     leave_type_id: int,
     *     start_date: string,
     *     end_date: string,
     *     total_days?: float|int|string,
     *     reason?: string|null,
     *     attachments?: mixed,
     * }|null  $attributes
     */
    public function handle(
        int $companyId,
        ?LeaveRequest $existing = null,
        ?array $attributes = null,
        bool $reserveBalance = true,
        bool $notify = true,
        ?UploadedFile $attachment = null,
    ): LeaveRequest {
        $storedAttachments = null;

        try {
            $leaveRequest = DB::transaction(function () use (
                $companyId,
                $existing,
                $attributes,
                $reserveBalance,
                $attachment,
                &$storedAttachments,
            ): LeaveRequest {
                if ($existing !== null) {
                    $leaveRequest = LeaveRequest::query()
                        ->whereKey($existing->id)
                        ->where('company_id', $companyId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($leaveRequest->status !== 'pending') {
                        throw new RuntimeException('Only pending leave requests can receive an approval chain.');
                    }

                    if ($leaveRequest->approvals()->exists()) {
                        throw new RuntimeException('This leave request already has an approval chain.');
                    }

                    $employeeId = (int) $leaveRequest->employee_id;
                    $leaveTypeId = (int) $leaveRequest->leave_type_id;
                    $startDate = (string) $leaveRequest->start_date?->toDateString();
                    $endDate = (string) $leaveRequest->end_date?->toDateString();
                    $totalDays = (float) $leaveRequest->total_days;
                    $reason = $leaveRequest->reason;
                } else {
                    if ($attributes === null) {
                        throw new RuntimeException('Leave request attributes are required when creating a new request.');
                    }

                    $employeeId = (int) $attributes['employee_id'];
                    $leaveTypeId = (int) $attributes['leave_type_id'];
                    $dates = $this->validateDateRange->handle(
                        $attributes['start_date'] ?? null,
                        $attributes['end_date'] ?? null,
                    );
                    $startDate = $dates['start_date'];
                    $endDate = $dates['end_date'];
                    // Domain-authoritative: ignore any caller-supplied total_days.
                    $totalDays = ($this->calculateDays)($startDate, $endDate);
                    $reason = $attributes['reason'] ?? null;
                }

                $employee = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey($employeeId)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($employee === null) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'The selected employee is invalid or inactive for this company.',
                    ]);
                }

                $leaveType = LeaveType::query()
                    ->where('company_id', $companyId)
                    ->whereKey($leaveTypeId)
                    ->where('status', 'active')
                    ->first();

                if ($leaveType === null) {
                    throw ValidationException::withMessages([
                        'leave_type_id' => 'The selected leave type is invalid or inactive for this company.',
                    ]);
                }

                $this->assertOverlap->handle(
                    companyId: $companyId,
                    employeeId: $employeeId,
                    startDate: $startDate,
                    endDate: $endDate,
                    excludeLeaveRequestId: $existing?->id,
                );

                try {
                    $chain = $this->resolveChain->handle($employee, $companyId);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'leave_request' => $exception->getMessage(),
                    ]);
                }

                if ($reserveBalance) {
                    $this->leaveBalances->reserveIfAvailable(
                        companyId: $companyId,
                        employeeId: $employeeId,
                        leaveTypeId: $leaveTypeId,
                        startDate: $startDate,
                        endDate: $endDate,
                    );
                }

                if ($existing === null) {
                    $leaveRequest = new LeaveRequest;
                    $leaveRequest->forceFill([
                        'company_id' => $companyId,
                        'employee_id' => $employeeId,
                        'leave_type_id' => $leaveTypeId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'total_days' => $totalDays,
                        'reason' => $reason,
                        'attachments' => null,
                        'status' => 'pending',
                    ])->save();

                    $leaveRequest = LeaveRequest::query()
                        ->whereKey($leaveRequest->id)
                        ->where('company_id', $companyId)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($attachment !== null) {
                    $storedAttachments = $this->attachments->store(
                        $attachment,
                        $companyId,
                        (int) $leaveRequest->id,
                    );
                    $leaveRequest->forceFill([
                        'attachments' => $storedAttachments,
                    ])->save();
                }

                $this->resolveChain->persistSnapshot($leaveRequest, $chain);

                $fresh = $leaveRequest->fresh(['approvals', 'employee', 'leaveType']) ?? $leaveRequest;
                $this->assertInvariant->forPendingRequest($fresh, $fresh->approvals);

                return $fresh;
            });
        } catch (Throwable $exception) {
            if ($storedAttachments !== null) {
                $this->attachments->deleteFromStorage($storedAttachments);
            }

            throw $exception;
        }

        if ($notify) {
            DB::afterCommit(function () use ($leaveRequest): void {
                try {
                    $this->sendSubmittedEmail->handle($leaveRequest->fresh() ?? $leaveRequest);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

        return $leaveRequest;
    }
}
