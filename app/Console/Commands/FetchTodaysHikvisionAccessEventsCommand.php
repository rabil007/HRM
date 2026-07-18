<?php

namespace App\Console\Commands;

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionSetting;
use App\Support\Hikvision\HikvisionEveningAccessEventsFetchSchedule;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Console\Command;

class FetchTodaysHikvisionAccessEventsCommand extends Command
{
    protected $signature = 'hikvision:fetch-todays-access-events {--force : Run even when evening fetch is disabled}';

    protected $description = 'Dispatch the manual-style fetch job for today\'s Hikvision access events';

    public function handle(): int
    {
        $date = now(ApplicationTimezone::identifier())->toDateString();
        $settings = $this->option('force')
            ? HikvisionSetting::query()->get()->filter(fn (HikvisionSetting $setting): bool => $setting->isConfigured())->values()
            : HikvisionEveningAccessEventsFetchSchedule::settingsDueForDispatch();

        if ($settings->isEmpty()) {
            if (! $this->option('force') && ! HikvisionEveningAccessEventsFetchSchedule::isEnabled()) {
                $this->line('Evening access events fetch is disabled.');
            } elseif (! $this->option('force')) {
                $this->line('No Hikvision companies are due for evening fetch.');
            }

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($settings as $setting) {
            if ($setting->isEventsFetchProcessing()) {
                continue;
            }

            $setting->beginEventsFetch();
            FetchHikvisionAccessEventsJob::dispatch($setting->id, $date);
            $dispatched++;
        }

        if ($dispatched === 1) {
            $this->info("Dispatched Hikvision access events fetch job for {$date}.");
        } else {
            $this->info("Dispatched {$dispatched} Hikvision access events fetch job(s) for {$date}.");
        }

        return self::SUCCESS;
    }
}
