<?php

namespace App\Console\Commands\Attendance;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\EmailTemplate;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\Actions\SendLeaveRequestSubmittedEmail;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class BackfillLeaveRequestApprovalsCommand extends Command
{
    protected $signature = 'leave-approvals:backfill
                            {--dry-run : Preview without writing any database rows}
                            {--company= : Limit to a company ID}
                            {--request= : Limit to a leave request ID}
                            {--force : Deprecated; never deletes or replaces approval history}
                            {--notify : Email the first pending approver after creating a snapshot}
                            {--fill-snapshot-metadata : Fill null policy provenance on existing approval rows without overwriting}';

    protected $description = 'Backfill leave_request_approvals snapshots for pending leave requests missing an approval chain';

    public function handle(SubmitLeaveRequestWithApprovals $submitWithApprovals): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $notifyRequested = (bool) $this->option('notify') && ! $dryRun;
        $fillMetadata = (bool) $this->option('fill-snapshot-metadata');
        $companyOption = $this->option('company');
        $requestOption = $this->option('request');

        $companyId = filled($companyOption) ? (int) $companyOption : null;
        $requestId = filled($requestOption) ? (int) $requestOption : null;

        if ($companyId !== null && ! Company::query()->whereKey($companyId)->exists()) {
            $this->error("Company [{$companyId}] was not found.");

            return self::FAILURE;
        }

        if ($force) {
            $this->warn('`--force` no longer deletes or replaces approval rows. Requests with existing approvals are skipped.');
        }

        $query = LeaveRequest::query()
            ->with(['employee', 'approvals'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($requestId !== null, fn ($q) => $q->whereKey($requestId))
            ->orderBy('id');

        $inspected = 0;
        $eligible = 0;
        $created = 0;
        $wouldCreate = 0;
        $skippedExisting = 0;
        $skippedNonPending = 0;
        $failedConfiguration = 0;
        $failedProcessing = 0;
        $notificationsScheduled = 0;
        $metadataFilled = 0;

        $query->chunkById(100, function ($leaveRequests) use (
            $submitWithApprovals,
            $dryRun,
            $notifyRequested,
            $fillMetadata,
            &$inspected,
            &$eligible,
            &$created,
            &$wouldCreate,
            &$skippedExisting,
            &$skippedNonPending,
            &$failedConfiguration,
            &$failedProcessing,
            &$notificationsScheduled,
            &$metadataFilled,
        ): void {
            foreach ($leaveRequests as $leaveRequest) {
                /** @var LeaveRequest $leaveRequest */
                $inspected++;

                if ($fillMetadata && $leaveRequest->approvals->isNotEmpty()) {
                    try {
                        $metadataFilled += $this->fillMissingSnapshotMetadata($leaveRequest, $dryRun);
                    } catch (Throwable $exception) {
                        $this->error("Metadata #{$leaveRequest->id}: {$exception->getMessage()}");
                        report($exception);
                        $failedProcessing++;
                    }
                }

                if ($leaveRequest->status !== 'pending') {
                    $this->line("Skip #{$leaveRequest->id}: not pending ({$leaveRequest->status}).");
                    $skippedNonPending++;

                    continue;
                }

                if ($leaveRequest->approvals->isNotEmpty()) {
                    if ((bool) $this->option('force')) {
                        $this->warn("Skip #{$leaveRequest->id}: already has approvals — destructive replacement is no longer supported.");
                    } else {
                        $this->line("Skip #{$leaveRequest->id}: already has approvals.");
                    }
                    $skippedExisting++;

                    continue;
                }

                if ($dryRun) {
                    try {
                        CompanyLeaveApprovalSetting::findForCompany((int) $leaveRequest->company_id);
                        app(ResolveLeaveApprovalChain::class)
                            ->handle($leaveRequest->employee, (int) $leaveRequest->company_id);
                        $this->info("Dry-run #{$leaveRequest->id}: would create approval chain.");
                        $eligible++;
                        $wouldCreate++;
                    } catch (RuntimeException $exception) {
                        $this->error("Dry-run #{$leaveRequest->id}: {$exception->getMessage()}");
                        $failedConfiguration++;
                    } catch (Throwable $exception) {
                        $this->error("Dry-run #{$leaveRequest->id}: {$exception->getMessage()}");
                        $failedProcessing++;
                    }

                    continue;
                }

                try {
                    $scopedCompanyId = (int) $leaveRequest->company_id;
                    $beforeApprovalCount = LeaveRequestApproval::query()
                        ->where('company_id', $scopedCompanyId)
                        ->where('leave_request_id', $leaveRequest->id)
                        ->count();

                    $submitWithApprovals->handle(
                        companyId: $scopedCompanyId,
                        existing: $leaveRequest,
                        attributes: null,
                        reserveBalance: false,
                        notify: false,
                    );

                    $afterApprovals = LeaveRequestApproval::query()
                        ->where('company_id', $scopedCompanyId)
                        ->where('leave_request_id', $leaveRequest->id)
                        ->orderBy('sequence')
                        ->with('approverEmployee.user:id,email')
                        ->get();

                    if ($afterApprovals->count() <= $beforeApprovalCount) {
                        throw new RuntimeException('Approval snapshot was not created.');
                    }

                    $this->info("Backfilled #{$leaveRequest->id}.");
                    $eligible++;
                    $created++;

                    if ($notifyRequested && $this->scheduleApproverNotification($leaveRequest->fresh(['approvals.approverEmployee.user', 'employee', 'leaveType', 'company']) ?? $leaveRequest, $afterApprovals)) {
                        $notificationsScheduled++;
                    }
                } catch (RuntimeException $exception) {
                    $this->error("Failed #{$leaveRequest->id}: {$exception->getMessage()}");
                    $failedConfiguration++;
                } catch (Throwable $exception) {
                    $this->error("Failed #{$leaveRequest->id}: {$exception->getMessage()}");
                    report($exception);
                    $failedProcessing++;
                }
            }
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Inspected', $inspected],
                ['Eligible', $eligible],
                ['Created', $created],
                ['Would create', $wouldCreate],
                ['Skipped existing', $skippedExisting],
                ['Skipped non-pending', $skippedNonPending],
                ['Failed configuration', $failedConfiguration],
                ['Failed processing', $failedProcessing],
                ['Notifications scheduled', $notificationsScheduled],
                ['Snapshot metadata filled', $metadataFilled],
                ['Mode', $dryRun ? 'dry-run' : 'write'],
            ],
        );

        return ($failedConfiguration + $failedProcessing) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals
     */
    private function scheduleApproverNotification(LeaveRequest $leaveRequest, $approvals): bool
    {
        $pending = $approvals->first(
            fn (LeaveRequestApproval $approval): bool => $approval->status === LeaveRequestApprovalStatus::Pending,
        );

        if ($pending === null) {
            return false;
        }

        $pending->loadMissing('approverEmployee.user:id,email');
        $email = $this->approverEmail($pending);

        if ($email === '') {
            return false;
        }

        $template = EmailTemplate::query()
            ->where('slug', 'leave_request_submitted')
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            return false;
        }

        try {
            app(SendLeaveRequestSubmittedEmail::class)
                ->handle($leaveRequest);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function approverEmail(LeaveRequestApproval $approval): string
    {
        $employee = $approval->approverEmployee;

        if ($employee === null) {
            return '';
        }

        if (filled($employee->work_email)) {
            return (string) $employee->work_email;
        }

        if (filled($employee->personal_email)) {
            return (string) $employee->personal_email;
        }

        return (string) ($employee->user?->email ?? '');
    }

    private function fillMissingSnapshotMetadata(LeaveRequest $leaveRequest, bool $dryRun): int
    {
        $filled = 0;
        $companyId = (int) $leaveRequest->company_id;

        $updatesByApprovalId = [];

        foreach ($leaveRequest->approvals as $approval) {
            /** @var LeaveRequestApproval $approval */
            $needsPolicy = $approval->policy_id === null || blank($approval->policy_name);
            $needsLabel = blank($approval->policy_step_label);

            if (! $needsPolicy && ! $needsLabel) {
                continue;
            }

            $updates = [];

            if (($needsPolicy || $needsLabel) && $approval->policy_step_id !== null) {
                $step = LeaveApprovalPolicyStep::query()
                    ->where('company_id', $companyId)
                    ->whereKey($approval->policy_step_id)
                    ->with('policy')
                    ->first();

                if ($step !== null) {
                    if ($approval->policy_id === null && $step->leave_approval_policy_id !== null) {
                        $updates['policy_id'] = (int) $step->leave_approval_policy_id;
                    }

                    if (blank($approval->policy_name) && $step->policy !== null) {
                        $updates['policy_name'] = (string) $step->policy->name;
                    }

                    if (blank($approval->policy_step_label) && $step->approver_type !== null) {
                        $updates['policy_step_label'] = sprintf(
                            'Step %d: %s',
                            (int) $approval->sequence,
                            $step->approver_type->label(),
                        );
                    }
                }
            }

            if ($needsPolicy && blank($updates['policy_name'] ?? null) && $approval->policy_id !== null) {
                $policyName = LeaveApprovalPolicy::query()
                    ->where('company_id', $companyId)
                    ->whereKey($approval->policy_id)
                    ->value('name');

                if (filled($policyName) && blank($approval->policy_name)) {
                    $updates['policy_name'] = (string) $policyName;
                }
            }

            if ($updates === []) {
                continue;
            }

            if ($dryRun) {
                $this->line("Dry-run #{$leaveRequest->id}/approval #{$approval->id}: would fill snapshot metadata.");
                $filled++;

                continue;
            }

            $safeUpdates = [];
            foreach ($updates as $key => $value) {
                if (blank($approval->{$key})) {
                    $safeUpdates[$key] = $value;
                }
            }

            if ($safeUpdates === []) {
                continue;
            }

            $updatesByApprovalId[(int) $approval->id] = $safeUpdates;
        }

        if ($dryRun || $updatesByApprovalId === []) {
            return $filled;
        }

        DB::transaction(function () use ($leaveRequest, $companyId, $updatesByApprovalId, &$filled): void {
            foreach ($updatesByApprovalId as $approvalId => $safeUpdates) {
                $approval = LeaveRequestApproval::query()
                    ->where('company_id', $companyId)
                    ->where('leave_request_id', $leaveRequest->id)
                    ->whereKey($approvalId)
                    ->lockForUpdate()
                    ->first();

                if ($approval === null) {
                    continue;
                }

                $finalUpdates = [];
                foreach ($safeUpdates as $key => $value) {
                    if (blank($approval->{$key})) {
                        $finalUpdates[$key] = $value;
                    }
                }

                if ($finalUpdates === []) {
                    continue;
                }

                $approval->forceFill($finalUpdates)->save();
                $filled++;
            }
        });

        return $filled;
    }
}
