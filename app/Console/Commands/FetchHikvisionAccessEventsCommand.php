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
        $settings = HikvisionSetting::current();

        if (! $settings->isConfigured()) {
            $this->warn('Hikvision integration is not configured.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! HikvisionAccessEventsFetchSchedule::isEnabled()) {
            $this->line('Scheduled access events fetch is disabled.');

            return self::SUCCESS;
        }

        if ($settings->isEventsFetchProcessing()) {
            $this->warn('A fetch is already in progress.');

            return self::SUCCESS;
        }

        $settings->beginEventsFetch();
        FetchHikvisionAccessEventsJob::dispatch();

        $this->info('Dispatched Hikvision access events fetch job.');

        return self::SUCCESS;
    }
}
