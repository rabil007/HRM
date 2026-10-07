<?php

namespace App\Console\Commands;

use App\Support\Recruitment\DispatchRequirementTargetDateReminders;
use Illuminate\Console\Command;

class DispatchRequirementTargetDateRemindersCommand extends Command
{
    protected $signature = 'recruitment:dispatch-target-date-reminders
                            {--company= : Limit dispatching to a specific company ID}
                            {--force : Force dispatch ignoring the company-local 09:00 window}';

    protected $description = 'Queue Recruitment Requirement Target Date reminders (3 days before and on the Target Date) for companies at local 09:00';

    public function handle(DispatchRequirementTargetDateReminders $dispatcher): int
    {
        $companyOption = $this->option('company');
        $force = (bool) $this->option('force');

        $onlyCompanyId = ($companyOption !== null && $companyOption !== '')
            ? (int) $companyOption
            : null;

        $results = $dispatcher->dispatchAll($force, $onlyCompanyId);

        $this->info(sprintf(
            'Checked %d company(s) — queued %d reminder job(s), skipped %d, errors %d.',
            $results['companies_checked'],
            $results['reminders_queued'],
            $results['skipped'],
            $results['errors'],
        ));

        return $results['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
