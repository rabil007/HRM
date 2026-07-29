<?php

namespace App\Support\Attendance\Actions;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SubmitLeaveRequestWithApprovals
{
    public function __construct(
        private ResolveLeaveApprovalChain $resolveChain,
        private LeaveBalanceManager $leaveBalances,
        private SendLeaveRequestSubmittedEmail $sendSubmittedEmail,
    ) {}

    /**
     * Create a pending leave request (or accept an existing one), persist its approval
     * snapshot, reserve balance, and optionally notify the first pending approver after commit.
     *
     * @param  array{
     *     employee_id: int,
     *     leave_type_id: int,
     *     start_date: string,
     *     end_date: string,
     *     total_days: float|int|string,
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
    ): LeaveRequest {
        $leaveRequest = DB::transaction(function () use ($companyId, $existing, $attributes, $reserveBalance): LeaveRequest {
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
            } else {
                if ($attributes === null) {
                    throw new RuntimeException('Leave request attributes are required when creating a new request.');
                }

                $leaveRequest = new LeaveRequest;
                $leaveRequest->forceFill([
                    'company_id' => $companyId,
                    'employee_id' => $attributes['employee_id'],
                    'leave_type_id' => $attributes['leave_type_id'],
                    'start_date' => $attributes['start_date'],
                    'end_date' => $attributes['end_date'],
                    'total_days' => $attributes['total_days'],
                    'reason' => $attributes['reason'] ?? null,
                    'attachments' => $attributes['attachments'] ?? null,
                    'status' => 'pending',
                ])->save();

                $leaveRequest = LeaveRequest::query()
                    ->whereKey($leaveRequest->id)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $leaveRequest->employee_id)
                ->firstOrFail();

            $chain = $this->resolveChain->handle($employee, $companyId);
            $this->resolveChain->persistSnapshot($leaveRequest, $chain);

            if ($reserveBalance) {
                $this->leaveBalances->reserveLeaveRequest($leaveRequest->fresh() ?? $leaveRequest);
            }

            return $leaveRequest->fresh(['approvals', 'employee', 'leaveType']) ?? $leaveRequest;
        });

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
