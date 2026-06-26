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
        $settings = HikvisionSetting::current();

        if (! $settings->isConfigured()) {
            $this->warn('Hikvision integration is not configured.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! HikvisionEveningAccessEventsFetchSchedule::isEnabled()) {
            $this->line('Evening access events fetch is disabled.');

            return self::SUCCESS;
        }

        if ($settings->isEventsFetchProcessing()) {
            $this->warn('A fetch is already in progress.');

            return self::SUCCESS;
        }

        $date = now(ApplicationTimezone::identifier())->toDateString();

        $settings->beginEventsFetch();
        FetchHikvisionAccessEventsJob::dispatch($date);

        $this->info("Dispatched Hikvision access events fetch job for {$date}.");

        return self::SUCCESS;
    }
}
