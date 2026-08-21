<?php

namespace App\Console\Commands;

use App\Support\CrewOperations\DispatchCrewOperationalAlertEmailDigests;
use Illuminate\Console\Command;

class DispatchCrewOperationalAlertEmailDigestsCommand extends Command
{
    protected $signature = 'crew:dispatch-operational-alert-email-digests
                            {--company= : Limit dispatching to a specific company ID}
                            {--force : Force dispatch ignoring scheduled time and today check}';

    protected $description = 'Dispatch daily scheduled email digests of queued Crew operational alerts for companies whose configured digest time is due';

    public function handle(DispatchCrewOperationalAlertEmailDigests $dispatcher): int
    {
        $companyOption = $this->option('company');
        $force = (bool) $this->option('force');

        $onlyCompanyId = ($companyOption !== null && $companyOption !== '')
            ? (int) $companyOption
            : null;

        $results = $dispatcher->dispatchAll($force, $onlyCompanyId);

        $this->info(sprintf(
            'Checked %d company(s) — dispatched %d digest(s) across %d job(s) for %d queued alert deliveries with %d error(s).',
            $results['companies_checked'],
            $results['digests_dispatched'],
            $results['jobs_dispatched'],
            $results['deliveries_included'],
            $results['errors'],
        ));

        return $results['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
