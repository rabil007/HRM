<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertEmailDeliveryMode;
use App\Jobs\DeliverCrewOperationalAlertEmailJob;
use App\Models\Company;
use App\Models\CrewOperationsSetting;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
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

        $mode = CrewOperationsSettings::emailDeliveryMode($companyId);

        if ($mode !== CrewOperationalAlertEmailDeliveryMode::Scheduled && ! $force) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'mode_not_scheduled',
            ];
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $now = CarbonImmutable::now($timezone);
        $digestTime = CrewOperationsSettings::emailDigestAt($companyId);
        $currentTime = $now->format('H:i');
        $todayLocal = $now->toDateString();

        if ($currentTime < $digestTime && ! $force) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'not_due_yet',
            ];
        }

        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if ($setting !== null && $setting->notification_email_last_digest_date === $todayLocal && ! $force) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'already_dispatched_today',
            ];
        }

        CrewOperationsSetting::query()->firstOrCreate(['company_id' => $companyId]);

        if (! $force) {
            $updated = CrewOperationsSetting::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $query) use ($todayLocal): void {
                    $query->whereNull('notification_email_last_digest_date')
                        ->orWhere('notification_email_last_digest_date', '<', $todayLocal);
                })
                ->update([
                    'notification_email_last_digest_date' => $todayLocal,
                    'notification_email_last_digest_dispatched_at' => now(),
                ]);

            if ($updated === 0) {
                return [
                    'dispatched' => false,
                    'jobs_count' => 0,
                    'delivery_count' => 0,
                    'reason' => 'concurrently_claimed',
                ];
            }
        } else {
            CrewOperationsSetting::query()
                ->where('company_id', $companyId)
                ->update([
                    'notification_email_last_digest_date' => $todayLocal,
                    'notification_email_last_digest_dispatched_at' => now(),
                ]);
        }

        $claimedDeliveries = ClaimCrewOperationalAlertEmailDeliveries::claimForCompany($companyId);

        if ($claimedDeliveries->isEmpty()) {
            return [
                'dispatched' => false,
                'jobs_count' => 0,
                'delivery_count' => 0,
                'reason' => 'no_pending_deliveries',
            ];
        }

        $grouped = $claimedDeliveries->groupBy('user_id');
        $jobsCount = 0;

        foreach ($grouped as $userId => $deliveries) {
            $deliveryIds = $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->all();
            DeliverCrewOperationalAlertEmailJob::dispatch($deliveryIds, $companyId, (int) $userId);
            $jobsCount++;
        }

        return [
            'dispatched' => true,
            'jobs_count' => $jobsCount,
            'delivery_count' => $claimedDeliveries->count(),
        ];
    }
}
