<?php

namespace App\Console\Commands;

use App\Support\CrewMovements\Scheduling\ProcessDueCrewScheduledMovements;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessCrewScheduledMovementsCommand extends Command
{
    protected $signature = 'crew:process-scheduled-movements
                            {--limit=25 : Maximum due schedules to claim in this run}
                            {--now= : Optional ISO-8601 UTC instant for deterministic testing}';

    protected $description = 'Claim and automatically execute due scheduled crew movements (cron-compatible)';

    public function handle(ProcessDueCrewScheduledMovements $processor): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $nowOption = $this->option('now');
        $now = is_string($nowOption) && $nowOption !== ''
            ? Carbon::parse($nowOption, 'UTC')
            : Carbon::now('UTC');

        $result = $processor->handle($limit, $now);

        $this->info(sprintf(
            'Claimed %d; executed %d; needs attention %d; recovered stale %d.',
            $result['claimed'],
            $result['executed'],
            $result['needs_attention'],
            $result['recovered'],
        ));

        return self::SUCCESS;
    }
}
