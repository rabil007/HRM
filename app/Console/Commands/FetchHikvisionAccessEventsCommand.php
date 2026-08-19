<?php

namespace App\Console\Commands;

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionSetting;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use App\Support\Hikvision\HikvisionFetchOrigin;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class FetchHikvisionAccessEventsCommand extends Command
{
    protected $signature = 'hikvision:fetch-access-events {--force : Run even when scheduled fetch is disabled}';

    protected $description = 'Dispatch the background job to fetch yesterday\'s Hikvision access events';

    public function handle(): int
    {
        $isForce = (bool) $this->option('force');
        $dueItems = $isForce
            ? $this->resolveForcedReconciliations()
            : HikvisionAccessEventsFetchSchedule::dueReconciliations();

        if ($dueItems->isEmpty()) {
            if (! $isForce && ! HikvisionAccessEventsFetchSchedule::isEnabled()) {
                $this->line('Scheduled access events fetch is disabled.');
            } elseif (! $isForce) {
                $this->line('No Hikvision companies are due for scheduled fetch.');
            }

            return self::SUCCESS;
        }

        $dispatched = 0;
        $dispatchedCompanies = [];

        foreach ($dueItems as $item) {
            $setting = $item['setting'];
            $companyId = (int) $setting->company_id;

            if (in_array($companyId, $dispatchedCompanies, true)) {
                continue;
            }

            $setting->resolveStaleEventsFetch(5);
            $setting->refresh();

            if ($setting->isEventsFetchProcessing()) {
                continue;
            }

            $setting->beginEventsFetch();
            FetchHikvisionAccessEventsJob::dispatch(
                $setting->id,
                $item['target_date'],
                $item['origin'],
            );

            $dispatchedCompanies[] = $companyId;
            $dispatched++;
        }

        if ($dispatched === 1) {
            $this->info('Dispatched Hikvision access events fetch job.');
        } else {
            $this->info("Dispatched {$dispatched} Hikvision access events fetch job(s).");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{setting: HikvisionSetting, target_date: string, origin: HikvisionFetchOrigin}>
     */
    private function resolveForcedReconciliations(): Collection
    {
        return HikvisionSetting::query()
            ->get()
            ->filter(fn (HikvisionSetting $setting): bool => $setting->isConfigured() && $setting->company_id !== null)
            ->map(function (HikvisionSetting $setting): array {
                $timezone = CompanyTimezone::forCompany((int) $setting->company_id);
                $yesterday = now($timezone)->subDay()->toDateString();

                return [
                    'setting' => $setting,
                    'target_date' => $yesterday,
                    'origin' => HikvisionFetchOrigin::ScheduledReconciliation,
                ];
            })
            ->values();
    }
}
