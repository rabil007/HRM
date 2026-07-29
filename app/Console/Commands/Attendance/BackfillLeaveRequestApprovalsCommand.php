<?php

namespace App\Console\Commands\Attendance;

use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use Illuminate\Console\Command;
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
        $notify = (bool) $this->option('notify') && ! $dryRun;
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
        $skippedExisting = 0;
        $skippedNonPending = 0;
        $failedConfiguration = 0;
        $failedProcessing = 0;
        $notifications = 0;
        $metadataFilled = 0;

        $query->chunkById(100, function ($leaveRequests) use (
            $submitWithApprovals,
            $dryRun,
            $notify,
            $fillMetadata,
            &$inspected,
            &$eligible,
            &$created,
            &$skippedExisting,
            &$skippedNonPending,
            &$failedConfiguration,
            &$failedProcessing,
            &$notifications,
            &$metadataFilled,
        ): void {
            foreach ($leaveRequests as $leaveRequest) {
                /** @var LeaveRequest $leaveRequest */
                $inspected++;

                if ($fillMetadata && $leaveRequest->approvals->isNotEmpty()) {
                    $metadataFilled += $this->fillMissingSnapshotMetadata($leaveRequest, $dryRun);
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

                $eligible++;

                if ($dryRun) {
                    try {
                        // Read-only resolution must not create settings rows.
                        CompanyLeaveApprovalSetting::findForCompany((int) $leaveRequest->company_id);
                        app(ResolveLeaveApprovalChain::class)
                            ->handle($leaveRequest->employee, (int) $leaveRequest->company_id);
                        $this->info("Dry-run #{$leaveRequest->id}: would create approval chain.");
                        $created++;
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
                    $beforeApprovalCount = LeaveRequestApproval::query()
                        ->where('leave_request_id', $leaveRequest->id)
                        ->count();

                    $submitWithApprovals->handle(
                        companyId: (int) $leaveRequest->company_id,
                        existing: $leaveRequest,
                        attributes: null,
                        reserveBalance: false,
                        notify: $notify,
                    );

                    $afterApprovalCount = LeaveRequestApproval::query()
                        ->where('leave_request_id', $leaveRequest->id)
                        ->count();

                    if ($afterApprovalCount <= $beforeApprovalCount) {
                        throw new RuntimeException('Approval snapshot was not created.');
                    }

                    $this->info("Backfilled #{$leaveRequest->id}.");
                    $created++;

                    if ($notify) {
                        $notifications++;
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
                ['Skipped existing', $skippedExisting],
                ['Skipped non-pending', $skippedNonPending],
                ['Failed configuration', $failedConfiguration],
                ['Failed processing', $failedProcessing],
                ['Notifications', $notifications],
                ['Snapshot metadata filled', $metadataFilled],
                ['Mode', $dryRun ? 'dry-run' : 'write'],
            ],
        );

        return ($failedConfiguration + $failedProcessing) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function fillMissingSnapshotMetadata(LeaveRequest $leaveRequest, bool $dryRun): int
    {
        $filled = 0;

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
                    ->where('company_id', $leaveRequest->company_id)
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
                    ->where('company_id', $leaveRequest->company_id)
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

            // Never overwrite non-null historical snapshot fields.
            $safeUpdates = [];
            foreach ($updates as $key => $value) {
                if (blank($approval->{$key})) {
                    $safeUpdates[$key] = $value;
                }
            }

            if ($safeUpdates === []) {
                continue;
            }

            $approval->forceFill($safeUpdates)->save();
            $filled++;
        }

        return $filled;
    }
}
