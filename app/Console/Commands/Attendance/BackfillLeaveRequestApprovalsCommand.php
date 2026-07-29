<?php

namespace App\Console\Commands\Attendance;

use App\Models\Company;
use App\Models\LeaveRequest;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('leave-approvals:backfill {--dry-run : Preview without writing approval rows} {--company= : Limit to a company ID} {--request= : Limit to a leave request ID} {--force : Re-process requests that already have approvals (skipped by default)}')]
#[Description('Backfill leave_request_approvals snapshots for pending leave requests missing an approval chain')]
class BackfillLeaveRequestApprovalsCommand extends Command
{
    public function handle(SubmitLeaveRequestWithApprovals $submitWithApprovals): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $companyOption = $this->option('company');
        $requestOption = $this->option('request');

        $companyId = filled($companyOption) ? (int) $companyOption : null;
        $requestId = filled($requestOption) ? (int) $requestOption : null;

        if ($companyId !== null && ! Company::query()->whereKey($companyId)->exists()) {
            $this->error("Company [{$companyId}] was not found.");

            return self::FAILURE;
        }

        $query = LeaveRequest::query()
            ->with(['employee', 'approvals'])
            ->where('status', 'pending')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($requestId !== null, fn ($q) => $q->whereKey($requestId))
            ->orderBy('id');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($leaveRequests) use (
            $submitWithApprovals,
            $dryRun,
            $force,
            &$processed,
            &$skipped,
            &$failed,
        ): void {
            foreach ($leaveRequests as $leaveRequest) {
                /** @var LeaveRequest $leaveRequest */
                $hasApprovals = $leaveRequest->approvals->isNotEmpty();

                if ($hasApprovals && ! $force) {
                    $this->line("Skip #{$leaveRequest->id}: already has approvals.");
                    $skipped++;

                    continue;
                }

                if ($hasApprovals && $force) {
                    $this->warn("Force #{$leaveRequest->id}: already has approvals — clearing and rebuilding.");

                    if (! $dryRun) {
                        $leaveRequest->approvals()->delete();
                        $leaveRequest->unsetRelation('approvals');
                    }
                }

                if ($dryRun) {
                    try {
                        app(ResolveLeaveApprovalChain::class)
                            ->handle($leaveRequest->employee, (int) $leaveRequest->company_id);
                        $this->info("Dry-run #{$leaveRequest->id}: would create approval chain.");
                        $processed++;
                    } catch (Throwable $exception) {
                        $this->error("Dry-run #{$leaveRequest->id}: {$exception->getMessage()}");
                        $failed++;
                    }

                    continue;
                }

                try {
                    $submitWithApprovals->handle(
                        companyId: (int) $leaveRequest->company_id,
                        existing: $leaveRequest,
                        attributes: null,
                        reserveBalance: false,
                    );
                    $this->info("Backfilled #{$leaveRequest->id}.");
                    $processed++;
                } catch (RuntimeException $exception) {
                    $this->error("Failed #{$leaveRequest->id}: {$exception->getMessage()}");
                    $failed++;
                } catch (Throwable $exception) {
                    $this->error("Failed #{$leaveRequest->id}: {$exception->getMessage()}");
                    report($exception);
                    $failed++;
                }
            }
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $processed],
                ['Skipped', $skipped],
                ['Failed', $failed],
                ['Mode', $dryRun ? 'dry-run' : 'write'],
            ],
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
