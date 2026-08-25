<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertEmailDeliveryMode;
use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Enums\CrewOperationalAlertSeverity;
use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use App\Models\Company;
use App\Models\CrewOperationalAlertEmailDelivery;
use App\Models\CrewOperationsSetting;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final class DispatchCrewOperationalAlertEmailDigests
{
    /**
     * @return array{
     *     companies_checked: int,
     *     digests_dispatched: int,
     *     jobs_dispatched: int,
     *     deliveries_included: int,
     *     errors: int
     * }
     */
    public function dispatchAll(bool $force = false, ?int $onlyCompanyId = null): array
    {
        $query = Company::query()
            ->where('status', 'active')
            ->orderBy('id');

        if ($onlyCompanyId !== null && $onlyCompanyId > 0) {
            $query->whereKey($onlyCompanyId);
        }

        $totals = [
            'companies_checked' => 0,
            'digests_dispatched' => 0,
            'jobs_dispatched' => 0,
            'deliveries_included' => 0,
            'errors' => 0,
        ];

        $query->chunkById(50, function (Collection $companies) use ($force, &$totals): void {
            foreach ($companies as $company) {
                $totals['companies_checked']++;
                $result = $this->forCompanySafe((int) $company->id, $force);

                if (isset($result['error'])) {
                    $totals['errors']++;

                    continue;
                }

                if ($result['dispatched']) {
                    $totals['digests_dispatched']++;
                    $totals['jobs_dispatched'] += $result['jobs_count'];
                    $totals['deliveries_included'] += $result['delivery_count'];
                }
            }
        });

        return $totals;
    }

    /**
     * @return array{
     *     dispatched: bool,
     *     jobs_count: int,
     *     delivery_count: int,
     *     reason?: string,
     *     error?: string
     * }
     */
    public function forCompanySafe(int $companyId, bool $force = false): array
    {
        try {
            return $this->forCompany($companyId, $force);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{
     *     dispatched: bool,
     *     jobs_count: int,
     *     delivery_count: int,
     *     reason?: string
     * }
     */
    public function forCompany(int $companyId, bool $force = false): array
    {
        $company = Company::query()
            ->whereKey($companyId)
            ->where('status', 'active')
            ->first();

        if ($company === null) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'company_inactive',
            ];
        }

        if (! CrewOperationsSettings::notificationsEnabled($companyId)) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'notifications_disabled',
            ];
        }

        $pendingDeliveries = CrewOperationalAlertEmailDelivery::query()
            ->with(['alert', 'user'])
            ->where('company_id', $companyId)
            ->where('status', CrewOperationalAlertEmailDeliveryStatus::Queued)
            ->whereNull('dispatched_at')
            ->where(function (Builder $query): void {
                $query->whereNull('dispatch_claimed_at')
                    ->orWhere('dispatch_claimed_at', '<', CarbonImmutable::now()->subMinutes(ClaimCrewOperationalAlertEmailDeliveries::STALE_CLAIM_TIMEOUT_MINUTES));
            })
            ->get();

        if ($pendingDeliveries->isEmpty()) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'no_pending_deliveries',
            ];
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $nowLocal = CarbonImmutable::now($timezone);
        $deliveryMode = CrewOperationsSettings::emailDeliveryMode($companyId);
        $criticalImmediate = CrewOperationsSettings::emailCriticalImmediate($companyId);
        $digestTime = CrewOperationsSettings::emailDigestAt($companyId);

        $eligibleDeliveryIds = [];

        foreach ($pendingDeliveries as $delivery) {
            if ($this->isDeliveryEligible(
                $delivery,
                $force,
                $deliveryMode,
                $criticalImmediate,
                $digestTime,
                $timezone,
                $nowLocal,
            )) {
                $eligibleDeliveryIds[] = (int) $delivery->id;
            }
        }

        if ($eligibleDeliveryIds === []) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'no_eligible_deliveries',
            ];
        }

        $claimedDeliveries = ClaimCrewOperationalAlertEmailDeliveries::claimByIds($eligibleDeliveryIds);

        if ($claimedDeliveries->isEmpty()) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'concurrently_claimed',
            ];
        }

        $grouped = $claimedDeliveries->groupBy('user_id');
        $jobsCount = 0;
        $dispatchedDeliveriesCount = 0;

        foreach ($grouped as $userId => $userDeliveries) {
            $userDeliveryIds = $userDeliveries->pluck('id')->map(fn ($id): int => (int) $id)->all();

            try {
                DeliverCrewOperationalAlertEmailJob::dispatch($userDeliveryIds, $companyId, (int) $userId);
            } catch (Throwable $exception) {
                ClaimCrewOperationalAlertEmailDeliveries::releaseClaim($userDeliveryIds);
                self::releaseJobUniqueLock($userDeliveryIds, $companyId, (int) $userId);
                report($exception);

                continue;
            }

            CrewOperationalAlertDeliveryHandoff::persistLedger(
                fn () => ClaimCrewOperationalAlertEmailDeliveries::markDispatched($userDeliveryIds),
                [
                    'company_id' => $companyId,
                    'user_id' => (int) $userId,
                    'delivery_ids' => $userDeliveryIds,
                    'failure_category' => 'email_dispatch_ledger',
                ],
            );

            $jobsCount++;
            $dispatchedDeliveriesCount += count($userDeliveryIds);
        }

        if ($jobsCount > 0) {
            CrewOperationsSetting::query()
                ->where('company_id', $companyId)
                ->update([
                    'notification_email_last_digest_date' => $nowLocal->toDateString(),
                    'notification_email_last_digest_dispatched_at' => CarbonImmutable::now(),
                ]);
        }

        return [
            'dispatched' => $jobsCount > 0,
            'jobs_count' => $jobsCount,
            'delivery_count' => $dispatchedDeliveriesCount,
        ];
    }

    /**
     * Determines whether an individual queued alert email delivery is eligible for dispatch.
     */
    public function isDeliveryEligible(
        CrewOperationalAlertEmailDelivery $delivery,
        bool $force,
        CrewOperationalAlertEmailDeliveryMode $deliveryMode,
        bool $criticalImmediate,
        string $digestTime,
        string $timezone,
        CarbonImmutable $nowLocal,
    ): bool {
        if ($force) {
            return true;
        }

        if ($deliveryMode === CrewOperationalAlertEmailDeliveryMode::Immediate) {
            return true;
        }

        if ($criticalImmediate && $delivery->alert?->severity === CrewOperationalAlertSeverity::Critical) {
            return true;
        }

        $queuedAt = $delivery->queued_at ?? now();
        $queuedLocal = CarbonImmutable::parse($queuedAt)->setTimezone($timezone);

        if ($queuedLocal->format('H:i') < $digestTime) {
            $targetDigestAt = $queuedLocal->setTimeFromTimeString($digestTime);
        } else {
            $targetDigestAt = $queuedLocal->addDay()->setTimeFromTimeString($digestTime);
        }

        return $nowLocal >= $targetDigestAt;
    }

    /**
     * Releases unique job lock if job enqueue threw before execution.
     *
     * @param  list<int>  $userDeliveryIds
     */
    public static function releaseJobUniqueLock(array $userDeliveryIds, int $companyId, int $userId): void
    {
        try {
            $job = new DeliverCrewOperationalAlertEmailJob($userDeliveryIds, $companyId, $userId);
            (new UniqueLock(Container::getInstance()->make(Cache::class)))->release($job);
        } catch (Throwable) {
            // Ignore cache release errors
        }
    }
}
