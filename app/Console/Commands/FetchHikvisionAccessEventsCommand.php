<?php

namespace App\Console\Commands;

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionSetting;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use Illuminate\Console\Command;

class FetchHikvisionAccessEventsCommand extends Command
{
    protected $signature = 'hikvision:fetch-access-events {--force : Run even when scheduled fetch is disabled}';

    protected $description = 'Dispatch the background job to fetch yesterday\'s Hikvision access events';

    public function handle(): int
    {
        $settings = $this->option('force')
            ? HikvisionSetting::query()->get()->filter(fn (HikvisionSetting $setting): bool => $setting->isConfigured())->values()
            : HikvisionAccessEventsFetchSchedule::settingsDueForDispatch();

        if ($settings->isEmpty()) {
            if (! $this->option('force') && ! HikvisionAccessEventsFetchSchedule::isEnabled()) {
                $this->line('Scheduled access events fetch is disabled.');
            } elseif (! $this->option('force')) {
                $this->line('No Hikvision companies are due for scheduled fetch.');
            }

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($settings as $setting) {
            if ($setting->isEventsFetchProcessing()) {
                continue;
            }

            $setting->beginEventsFetch();
            FetchHikvisionAccessEventsJob::dispatch($setting->id);
            $dispatched++;
        }

        if ($dispatched === 1) {
            $this->info('Dispatched Hikvision access events fetch job.');
        } else {
            $this->info("Dispatched {$dispatched} Hikvision access events fetch job(s).");
        }

        return self::SUCCESS;
    }
}
